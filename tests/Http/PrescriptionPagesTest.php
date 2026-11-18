<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class PrescriptionPagesTest extends DbTestCase
{
    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    /** @return array{0:array,1:array} medico logado e paciente (default: menor de idade) */
    private function setupDoctor(string $birth = '2015-05-10'): array
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => $birth, 'sex' => 'M', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->loginAs($med);
        return [$med, $p];
    }

    private function rx(array $over = []): array
    {
        return $over + [
            'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => date('Y-m-d', strtotime('-30 days')),
            'dose' => '500 mg', 'frequency' => '2 vezes ao dia', 'instructions' => 'Tomar com alimento',
            'family_logs' => '1',
        ];
    }

    private function create(int $pid, array $over = []): int
    {
        $res = $this->post('/pacientes/' . $pid . '/tratamento/nova', $this->rx($over));
        $this->assertSame(302, $res->status);
        $this->assertMatchesRegularExpression('#^/prescricoes/(\d+)$#', $res->headers['Location']);
        return (int) preg_replace('#\D#', '', $res->headers['Location']);
    }

    private function n(string $sql, array $params = []): int
    {
        return (int) $this->db->one($sql, $params)['n'];
    }

    public function test_doctor_creates_prescription_and_changes_dose(): void
    {
        [, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $this->assertSame(200, $this->get('/pacientes/' . $pid . '/tratamento/nova')->status);
        $id = $this->create($pid);

        $form = $this->get('/prescricoes/' . $id . '/dose');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('500 mg', $form->body);
        $this->assertStringContainsString('value="' . date('Y-m-d') . '"', $form->body);

        $res = $this->post('/prescricoes/' . $id . '/dose', [
            'effective_date' => date('Y-m-d'), 'dose' => '850 mg', 'frequency' => '2 vezes ao dia',
            'instructions' => '', 'reason' => 'Ajuste após consulta',
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame('/prescricoes/' . $id, $res->headers['Location']);

        $show = $this->get('/prescricoes/' . $id);
        $this->assertSame(200, $show->status);
        $this->assertStringContainsString('500 mg', $show->body);
        $this->assertStringContainsString('850 mg', $show->body);
        $this->assertStringContainsString('Ajuste após consulta', $show->body);

        $index = $this->get('/pacientes/' . $pid . '/tratamento');
        $this->assertSame(200, $index->status);
        $this->assertStringContainsString('Metformina', $index->body);
        $this->assertStringContainsString('850 mg', $index->body);
        $this->assertGreaterThanOrEqual(1, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'view' AND entity = 'prescriptions' AND patient_id = ?", [$pid]));
    }

    public function test_validation_keeps_values(): void
    {
        [, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $res = $this->post('/pacientes/' . $pid . '/tratamento/nova', $this->rx(['dose' => '', 'drug_name' => 'Droga Preservada']));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('Droga Preservada', $res->body);
        $this->assertStringContainsString('Informe a dose.', $res->body);
        $this->assertSame(0, $this->n('SELECT COUNT(*) n FROM prescriptions WHERE patient_id = ?', [$pid]));
    }

    public function test_secret_checkbox_only_for_minor(): void
    {
        [, $minor] = $this->setupDoctor('2015-05-10');
        $this->assertStringContainsString('name="secret"', $this->get('/pacientes/' . $minor['id'] . '/tratamento/nova')->body);
        $adult = $this->makePatient(['birth_date' => '1985-01-01', 'sex' => 'F']);
        $this->assertStringNotContainsString('name="secret"', $this->get('/pacientes/' . $adult['id'] . '/tratamento/nova')->body);
    }

    public function test_edit_keeps_secret_flag(): void
    {
        [, $p] = $this->setupDoctor();
        $id = $this->create((int) $p['id'], ['secret' => '1']);
        $this->assertSame(1, (int) $this->db->one('SELECT secret FROM prescriptions WHERE id = ?', [$id])['secret']);
        $form = $this->get('/prescricoes/' . $id . '/editar');
        $this->assertSame(200, $form->status);
        $this->assertMatchesRegularExpression('/name="secret"[^>]*checked/', $form->body);
        $res = $this->post('/prescricoes/' . $id . '/editar', $this->rx(['secret' => '1', 'notes' => 'nota']));
        $this->assertSame(302, $res->status);
        $this->assertSame(1, (int) $this->db->one('SELECT secret FROM prescriptions WHERE id = ?', [$id])['secret']);
    }

    public function test_nutritionist_secretary_and_family_get_403(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $id = $this->create($pid);
        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $sec = $this->makeUser('secretaria');
        $resp = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $pid, 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $med['id']]);
        $adultUser = $this->makeUser('paciente');
        $adult = $this->makePatient(['birth_date' => '1985-01-01', 'user_id' => $adultUser['id'], 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        foreach ([$nutri, $sec, $resp, $adultUser] as $u) {
            $this->loginAs($u);
            $target = $u['role'] === 'paciente' ? (int) $adult['id'] : $pid;
            $this->assertSame(403, $this->get('/pacientes/' . $target . '/tratamento')->status, $u['role']);
            $this->assertSame(403, $this->get('/pacientes/' . $target . '/tratamento/nova')->status, $u['role']);
            $this->assertSame(403, $this->get('/prescricoes/' . $id)->status, $u['role']);
            $this->assertSame(403, $this->post('/prescricoes/' . $id . '/excluir', [])->status, $u['role']);
            $this->assertSame(403, $this->post('/prescricoes/' . $id . '/encerrar', [])->status, $u['role']);
        }
        $this->assertNull($this->db->one('SELECT deleted_at FROM prescriptions WHERE id = ?', [$id])['deleted_at']);
        $this->assertGreaterThanOrEqual(4, $this->n("SELECT COUNT(*) n FROM audit_log WHERE action = 'denied' AND entity = 'prescriptions'"));
    }

    public function test_end_and_delete_flow(): void
    {
        [, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $a = $this->create($pid);
        $b = $this->create($pid, ['drug_name' => 'Outra Droga']);

        $res = $this->post('/prescricoes/' . $a . '/encerrar', ['end_date' => date('Y-m-d', strtotime('-1 day')), 'reason' => 'Fim do ciclo']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/prescricoes/' . $a, $res->headers['Location']);
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), $this->db->one('SELECT end_date FROM prescriptions WHERE id = ?', [$a])['end_date']);
        $this->assertStringContainsString('Fim do ciclo', $this->get('/prescricoes/' . $a)->body);

        $res = $this->post('/prescricoes/' . $b . '/excluir', []);
        $this->assertSame(302, $res->status);
        $this->assertSame('/pacientes/' . $pid . '/tratamento', $res->headers['Location']);
        $index = $this->get('/pacientes/' . $pid . '/tratamento');
        $this->assertStringNotContainsString('Outra Droga', $index->body);
        $this->assertStringContainsString('<details', $index->body);
        $this->assertSame(404, $this->get('/prescricoes/' . $b)->status);
        $this->assertSame(404, $this->get('/prescricoes/999999')->status);
    }

    public function test_patient_card_shows_treatment_only_for_doctor(): void
    {
        [$med, $p] = $this->setupDoctor();
        $pid = (int) $p['id'];
        $this->create($pid, ['next_review_date' => date('Y-m-d', strtotime('+10 days'))]);
        $body = $this->get('/pacientes/' . $pid)->body;
        $this->assertStringContainsString('id="cartao-tratamento"', $body);
        $this->assertStringContainsString('Metformina — 500 mg', $body);
        $this->assertStringContainsString('Próxima avaliação: ' . date('d/m/Y', strtotime('+10 days')), $body);

        $nutri = $this->makeUser('nutricionista');
        Container::accessGrants()->grant($pid, (int) $nutri['id'], (int) $med['id']);
        $this->loginAs($nutri);
        $this->assertStringNotContainsString('cartao-tratamento', $this->get('/pacientes/' . $pid)->body);
    }

    public function test_pages_never_show_dose_suggestions(): void
    {
        [, $p] = $this->setupDoctor();
        $id = $this->create((int) $p['id']);
        $this->post('/prescricoes/' . $id . '/dose', ['effective_date' => date('Y-m-d'), 'dose' => '850 mg', 'frequency' => 'x', 'reason' => 'y']);
        foreach (['/prescricoes/' . $id, '/pacientes/' . $p['id'] . '/tratamento', '/pacientes/' . $p['id']] as $path) {
            $body = mb_strtolower($this->get($path)->body);
            foreach (['aument', 'reduz', 'sugest'] as $w) {
                $this->assertStringNotContainsString($w, $body, $path . ' ' . $w);
            }
        }
    }
}
