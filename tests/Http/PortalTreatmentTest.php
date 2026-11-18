<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class PortalTreatmentTest extends DbTestCase
{
    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data = []): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        Session::reset();
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
    }

    /** @return array{0:array,1:array,2:array} responsavel, paciente (8 anos), medico */
    private function family(): array
    {
        $med = $this->makeUser('medico');
        $g = $this->makeUser('responsavel');
        $p = $this->makePatient([
            'full_name' => 'Ana Souza', 'birth_date' => (date('Y') - 8) . '-03-10', 'sex' => 'F',
            'responsible_professional_id' => $med['id'], 'created_by' => $med['id'],
        ]);
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        return [$g, $p, $med];
    }

    private function rx(int $pid, array $med, array $over = []): int
    {
        return Container::prescriptions()->create($pid, $over + [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => $this->day(-30),
            'dose' => '500 mg', 'frequency' => '2 vezes ao dia', 'instructions' => 'Tomar com alimento',
            'family_logs' => '1', 'next_review_date' => $this->day(20),
        ], $med, date('Y-m-d'), new Request('POST', '/'));
    }

    private function logs(int $rx): int
    {
        return (int) $this->db->one('SELECT COUNT(*) c FROM medication_logs WHERE prescription_id = ? AND deleted_at IS NULL', [$rx])['c'];
    }

    public function test_guardian_sees_treatment_and_records_dose(): void
    {
        [$g, $p, $med] = $this->family();
        $pid = (int) $p['id'];
        $rx = $this->rx($pid, $med);
        $this->loginAs($g);

        $home = $this->get('/portal/p/' . $pid)->body;
        $this->assertStringContainsString('Veja os medicamentos e registre as doses.', $home);

        $page = $this->get('/portal/p/' . $pid . '/tratamento');
        $this->assertSame(200, $page->status);
        foreach (['Metformina', 'Oral', 'Dose: 500 mg', 'Como usar: 2 vezes ao dia', 'Tomar com alimento', 'Desde ' . fmt_date($this->day(-30)), 'Próxima avaliação com a Dra. Rubia: ' . fmt_date($this->day(20)), 'Registrar dose', 'Efeitos e intercorrências', 'Contar um efeito ou intercorrência'] as $s) {
            $this->assertStringContainsString($s, $page->body);
        }

        $res = $this->post('/portal/p/' . $pid . '/tratamento/' . $rx . '/dose', ['data' => date('Y-m-d'), 'hora' => '00:01']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $pid . '/tratamento', $res->headers['Location']);
        $this->assertSame(1, $this->logs($rx));
        $this->assertStringContainsString('Dose registrada.', $this->get('/portal/p/' . $pid . '/tratamento')->body);
    }

    public function test_guardian_never_sees_secret_prescription_trail(): void
    {
        [$g, $p, $med] = $this->family();
        $pid = (int) $p['id'];
        $rx = $this->rx($pid, $med, ['secret' => '1', 'drug_name' => 'Segredinho']);
        $this->db->insert('adverse_events', [
            'patient_id' => $pid, 'prescription_id' => $rx, 'occurred_on' => date('Y-m-d'), 'description' => 'Enjoo forte',
            'source' => 'familia', 'reported_by' => $g['id'], 'reported_by_role' => 'responsavel',
        ]);
        $this->loginAs($g);

        $this->assertStringNotContainsString('Veja os medicamentos', $this->get('/portal/p/' . $pid)->body);
        $page = $this->get('/portal/p/' . $pid . '/tratamento');
        $this->assertStringNotContainsString('Segredinho', $page->body);
        $this->assertStringNotContainsString('Enjoo forte', $page->body);
        $res = $this->post('/portal/p/' . $pid . '/tratamento/' . $rx . '/dose', ['data' => date('Y-m-d'), 'hora' => '00:01']);
        $this->assertSame(404, $res->status);
        $this->assertSame(0, $this->logs($rx));
        $form = $this->get('/portal/p/' . $pid . '/tratamento/relato');
        $this->assertStringNotContainsString('Segredinho', $form->body);
        $res = $this->post('/portal/p/' . $pid . '/tratamento/relato', ['data' => date('Y-m-d'), 'descricao' => 'x', 'medicamento' => (string) $rx]);
        $this->assertSame(404, $res->status);
    }

    public function test_portal_hides_internal_fields(): void
    {
        [$g, $p, $med] = $this->family();
        $pid = (int) $p['id'];
        $rx = $this->rx($pid, $med, ['notes' => 'NOTA-INTERNA-XYZ']);
        Container::prescriptions()->changeDose($rx, ['effective_date' => date('Y-m-d'), 'dose' => '850 mg', 'frequency' => '1x', 'instructions' => '', 'reason' => 'MOTIVO-DOSE-XYZ'], (int) $med['id'], date('Y-m-d'), new Request('POST', '/'));
        $ended = $this->rx($pid, $med, ['drug_name' => 'Encerrada', 'start_date' => $this->day(-20)]);
        Container::prescriptions()->end($ended, $this->day(-3), 'MOTIVO-FIM-XYZ', (int) $med['id'], date('Y-m-d'), new Request('POST', '/'));
        $this->loginAs($g);
        $body = $this->get('/portal/p/' . $pid . '/tratamento')->body;
        $this->assertStringContainsString('Dose: 850 mg', $body);
        $this->assertStringContainsString('Encerrada — encerrado em ' . fmt_date($this->day(-3)), $body);
        foreach (['NOTA-INTERNA-XYZ', 'MOTIVO-DOSE-XYZ', 'MOTIVO-FIM-XYZ'] as $s) {
            $this->assertStringNotContainsString($s, $body);
        }
    }

    public function test_record_outside_window_shows_error_422(): void
    {
        [$g, $p, $med] = $this->family();
        $pid = (int) $p['id'];
        $rx = $this->rx($pid, $med);
        $this->loginAs($g);
        $res = $this->post('/portal/p/' . $pid . '/tratamento/' . $rx . '/dose', ['data' => $this->day(-10), 'hora' => '10:00']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Só é possível registrar doses dos últimos 7 dias.', $res->body);
        $this->assertSame(0, $this->logs($rx));
    }

    public function test_remove_own_log_only(): void
    {
        [$g, $p, $med] = $this->family();
        $pid = (int) $p['id'];
        $rx = $this->rx($pid, $med);
        $other = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $pid, 'user_id' => $other['id'], 'relationship' => 'pai', 'created_by' => $med['id']]);
        $this->loginAs($other);
        $this->post('/portal/p/' . $pid . '/tratamento/' . $rx . '/dose', ['data' => date('Y-m-d'), 'hora' => '00:01']);
        $theirs = (int) $this->db->one('SELECT id FROM medication_logs WHERE prescription_id = ?', [$rx])['id'];
        $this->loginAs($g);
        $this->post('/portal/p/' . $pid . '/tratamento/' . $rx . '/dose', ['data' => date('Y-m-d'), 'hora' => '00:02']);
        $mine = (int) $this->db->one('SELECT id FROM medication_logs WHERE prescription_id = ? AND recorded_by = ?', [$rx, $g['id']])['id'];

        $page = $this->get('/portal/p/' . $pid . '/tratamento')->body;
        $this->assertStringContainsString('/tratamento/doses/' . $mine . '/remover', $page);
        $this->assertStringNotContainsString('/tratamento/doses/' . $theirs . '/remover', $page);

        $this->assertSame(404, $this->post('/portal/p/' . $pid . '/tratamento/doses/' . $theirs . '/remover')->status);
        $this->assertSame(2, $this->logs($rx));
        $res = $this->post('/portal/p/' . $pid . '/tratamento/doses/' . $mine . '/remover');
        $this->assertSame(302, $res->status);
        $this->assertSame(1, $this->logs($rx));
    }

    public function test_guardian_cannot_touch_other_patients_records(): void
    {
        [$g, $a, $med] = $this->family();
        $b = $this->makePatient([
            'full_name' => 'Bia Lima', 'birth_date' => (date('Y') - 9) . '-01-10', 'sex' => 'F',
            'responsible_professional_id' => $med['id'], 'created_by' => $med['id'],
        ]);
        $aid = (int) $a['id'];
        $bid = (int) $b['id'];
        $rxB = $this->rx($bid, $med);
        $rxA = $this->rx($aid, $med);
        $g2 = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $bid, 'user_id' => $g2['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        $this->loginAs($g2);
        $this->post('/portal/p/' . $bid . '/tratamento/' . $rxB . '/dose', ['data' => date('Y-m-d'), 'hora' => '00:01']);
        $logB = (int) $this->db->one('SELECT id FROM medication_logs WHERE prescription_id = ?', [$rxB])['id'];

        $this->loginAs($g);
        $d = ['data' => date('Y-m-d'), 'hora' => '00:01'];
        $this->assertSame(404, $this->post('/portal/p/' . $aid . '/tratamento/' . $rxB . '/dose', $d)->status);
        $this->assertSame(403, $this->post('/portal/p/' . $bid . '/tratamento/' . $rxB . '/dose', $d)->status);
        $this->assertSame(404, $this->post('/portal/p/' . $aid . '/tratamento/doses/' . $logB . '/remover')->status);
        $this->assertSame(403, $this->post('/portal/p/' . $bid . '/tratamento/doses/' . $logB . '/remover')->status);
        $this->assertSame(404, $this->post('/portal/p/' . $aid . '/tratamento/relato', ['data' => date('Y-m-d'), 'descricao' => 'x', 'medicamento' => (string) $rxB])->status);
        $this->assertSame(1, $this->logs($rxB));
        $this->assertSame(0, $this->logs($rxA));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM adverse_events')['c']);
    }

    public function test_report_effect_shows_urgency_notice_and_appears_for_doctor(): void
    {
        [$g, $p, $med] = $this->family();
        $pid = (int) $p['id'];
        $rx = $this->rx($pid, $med);
        $this->loginAs($g);
        $form = $this->get('/portal/p/' . $pid . '/tratamento/relato');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('Este relato não é visto na hora. Em caso de urgência, procure atendimento ou um pronto-socorro.', $form->body);
        $this->assertStringContainsString('Metformina', $form->body);

        $res = $this->post('/portal/p/' . $pid . '/tratamento/relato', ['data' => date('Y-m-d'), 'descricao' => 'Dor de barriga depois do remédio', 'medicamento' => (string) $rx]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $pid . '/tratamento', $res->headers['Location']);
        $page = $this->get('/portal/p/' . $pid . '/tratamento')->body;
        $this->assertStringContainsString('Relato enviado. A Dra. Rubia verá na próxima vez que abrir o sistema.', $page);
        $this->assertStringContainsString('Dor de barriga depois do remédio', $page);

        $bad = $this->post('/portal/p/' . $pid . '/tratamento/relato', ['data' => date('Y-m-d'), 'descricao' => '']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Descreva o que aconteceu.', $bad->body);

        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$med['id']]);
        $this->loginAs($med);
        $this->assertStringContainsString('Dor de barriga depois do remédio', $this->get('/pacientes/' . $pid . '/tratamento')->body);
    }

    public function test_child_account_is_redirected_from_treatment(): void
    {
        [, $p] = $this->family();
        $u = $this->makeUser('crianca', ['email' => null, 'username' => 'kid.' . bin2hex(random_bytes(3))]);
        $this->db->run('UPDATE patients SET child_user_id = ? WHERE id = ?', [$u['id'], $p['id']]);
        $this->loginAs($u);
        $res = $this->get('/portal/p/' . $p['id'] . '/tratamento');
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $p['id'] . '/metas', $res->headers['Location']);
        $this->assertSame(302, $this->get('/portal/p/' . $p['id'] . '/tratamento/relato')->status);
    }

    public function test_adult_patient_sees_former_secret(): void
    {
        $med = $this->makeUser('medico');
        $u = $this->makeUser('paciente');
        $p = $this->makePatient([
            'full_name' => 'Joao Adulto', 'birth_date' => (date('Y') - 30) . '-03-10', 'sex' => 'M',
            'responsible_professional_id' => $med['id'], 'created_by' => $med['id'], 'user_id' => $u['id'],
        ]);
        $pid = (int) $p['id'];
        $rx = $this->rx($pid, $med, ['drug_name' => 'Antigo sigiloso']);
        $this->db->run('UPDATE prescriptions SET secret = 1 WHERE id = ?', [$rx]);
        $this->loginAs($u);
        $page = $this->get('/portal/p/' . $pid . '/tratamento');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Antigo sigiloso', $page->body);
        $this->assertStringContainsString('Veja os medicamentos', $this->get('/portal/p/' . $pid)->body);
    }
}
