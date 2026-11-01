<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class RecordPagesTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function post(string $path, array $data = []): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function evo(array $over = []): array
    {
        return $over + ['anamnese' => 'Queixa', 'exame_fisico' => 'Normal', 'diagnostico' => 'Obesidade', 'conduta' => 'Dieta',
            'plano_terapeutico' => '', 'encaminhamentos' => '', 'cid10' => 'E66.0', 'encounter_date' => date('d/m/Y')];
    }

    public function testDoctorSavesEvolutionWithCid(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        $res = $this->post('/pacientes/' . $p['id'] . '/prontuario/evolucao', $this->evo(['cid10' => 'e66.0']));
        $this->assertSame(302, $res->status);
        $row = $this->db->one("SELECT * FROM clinical_entries WHERE kind='evolucao'");
        $this->assertSame('E66.0', $row['cid10']);
        $this->assertSame(date('Y-m-d'), $row['encounter_date']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='create' AND entity='clinical_entries'")['c']);
        $this->assertStringContainsString('Obesidade', $this->get('/pacientes/' . $p['id'] . '/prontuario')->body);
    }

    public function testInvalidCidRejected(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        $res = $this->post('/pacientes/' . $p['id'] . '/prontuario/evolucao', $this->evo(['cid10' => 'XYZ']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('CID-10 inválido', $res->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM clinical_entries')['c']);
    }

    public function testNutritionistWithGrantAndSecretaryForbidden(): void
    {
        $n = $this->makeUser('nutricionista');
        $p = $this->makePatient(['responsible_professional_id' => $n['id']]);
        $this->db->insert('professional_access', ['patient_id' => $p['id'], 'professional_id' => $n['id'], 'granted_by' => $n['id']]);
        $this->loginAs($n);
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/prontuario')->status);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='denied' AND user_id = ?", [$n['id']])['c']);
        $summary = $this->get('/pacientes/' . $p['id']);
        $this->assertStringNotContainsString('cartao-prontuario', $summary->body);

        \App\Core\Session::reset();
        $s = $this->makeUser('secretaria');
        $this->loginAs($s);
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/prontuario')->status);
        $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/prontuario/evolucao', $this->evo())->status);
    }

    public function testOpeningAuditsView(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        $this->assertSame(200, $this->get('/pacientes/' . $p['id'] . '/prontuario')->status);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='view' AND entity='clinical_entries' AND user_id = ?", [$m['id']])['c']);
    }

    public function testGestacionalForAdultIs404AndUnknownKind404(): void
    {
        $m = $this->makeUser('medico');
        $adult = $this->makePatient(['birth_date' => '1985-01-01']);
        $kid = $this->makePatient(['birth_date' => date('Y-m-d', strtotime('-8 years'))]);
        $this->loginAs($m);
        $this->assertSame(404, $this->get('/pacientes/' . $adult['id'] . '/prontuario/gestacional')->status);
        $this->assertSame(404, $this->get('/pacientes/' . $adult['id'] . '/prontuario/xyz')->status);
        $this->assertSame(200, $this->get('/pacientes/' . $kid['id'] . '/prontuario/gestacional')->status);
    }

    public function testHistoryReviseAndRemoveFlow(): void
    {
        $m = $this->makeUser('medico');
        $kid = $this->makePatient(['birth_date' => date('Y-m-d', strtotime('-8 years'))]);
        $this->loginAs($m);
        $base = '/pacientes/' . $kid['id'] . '/prontuario';
        $res = $this->post($base . '/gestacional', ['peso_nascer_g' => '3200', 'comprimento_nascer_cm' => '49,5', 'idade_gestacional_semanas' => '39',
            'tipo_parto' => 'cesárea', 'aleitamento_materno_meses' => '6', 'diabetes_gestacional' => '1', 'intercorrencias' => 'Nenhuma']);
        $this->assertSame(302, $res->status);
        $e = $this->db->one("SELECT * FROM clinical_entries WHERE kind='gestacional'");
        $pl = json_decode($e['payload'], true);
        $this->assertSame(3200, $pl['peso_nascer_g']);
        $this->assertTrue($pl['diabetes_gestacional']);

        $bad = $this->post($base . '/gestacional', ['peso_nascer_g' => '10', 'tipo_parto' => 'normal']);
        $this->assertSame(200, $bad->status);

        $this->assertSame(200, $this->get('/prontuario/' . $e['id'] . '/revisar')->status);
        $this->assertSame(302, $this->post('/prontuario/' . $e['id'] . '/revisar', ['peso_nascer_g' => '3300', 'tipo_parto' => 'normal'])->status);
        $this->assertSame(2, (int) $this->db->one("SELECT COUNT(*) c FROM clinical_entries WHERE kind='gestacional'")['c']);
        $v = $this->get('/prontuario/' . $e['id'] . '/versoes');
        $this->assertSame(200, $v->status);
        $this->assertStringContainsString('Versão 2', $v->body);
        $this->assertSame(404, $this->get('/prontuario/99999/versoes')->status);

        $cur = $this->db->one("SELECT id FROM clinical_entries WHERE kind='gestacional' AND superseded_at IS NULL");
        $this->post('/prontuario/' . $cur['id'] . '/remover', ['motivo' => '']);
        $this->assertNull($this->db->one('SELECT deleted_at FROM clinical_entries WHERE id = ?', [$cur['id']])['deleted_at']);
        $this->post('/prontuario/' . $cur['id'] . '/remover', ['motivo' => 'Engano']);
        $this->assertNotNull($this->db->one('SELECT deleted_at FROM clinical_entries WHERE id = ?', [$cur['id']])['deleted_at']);
    }

    public function testFamilyHistoryConditions(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        $this->post('/pacientes/' . $p['id'] . '/prontuario/historico_familiar', [
            'cond' => ['obesidade' => '1', 'diabetes tipo 2' => '1'],
            'parentesco' => ['obesidade' => ['pai', 'mãe'], 'diabetes tipo 2' => ['avô/avó materno(a)']],
            'observacoes' => 'obs',
        ]);
        $e = $this->db->one("SELECT payload FROM clinical_entries WHERE kind='historico_familiar'");
        $pl = json_decode($e['payload'], true);
        $this->assertCount(3, $pl['condicoes']);
        $this->assertSame(['condicao' => 'obesidade', 'parentesco' => 'pai'], $pl['condicoes'][0]);
    }

    public function testInvalidEncounterDateKeepsTypedNote(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        foreach (['31/02/2026', 'ontem'] as $bad) {
            $res = $this->post('/pacientes/' . $p['id'] . '/prontuario/evolucao',
                $this->evo(['encounter_date' => $bad, 'anamnese' => 'Nota longa da doutora']));
            $this->assertSame(200, $res->status, $bad);
            $this->assertStringContainsString('Data do atendimento inválida.', $res->body);
            $this->assertStringContainsString('Nota longa da doutora', $res->body);
            $this->assertStringContainsString('value="' . $bad . '"', $res->body);
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM clinical_entries')['c']);
    }

    public function testInvalidEncounterDateOnReviseRerendersForm(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        $this->post('/pacientes/' . $p['id'] . '/prontuario/evolucao', $this->evo(['encounter_date' => '10/01/2026']));
        $e = $this->db->one("SELECT * FROM clinical_entries WHERE kind='evolucao'");
        $res = $this->post('/prontuario/' . $e['id'] . '/revisar', $this->evo(['encounter_date' => '31/02/2026', 'conduta' => 'Conduta revisada']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Data do atendimento inválida.', $res->body);
        $this->assertStringContainsString('Conduta revisada', $res->body);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM clinical_entries')['c']);
    }

    public function testBlankEncounterDateOnReviseKeepsExistingDate(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        $this->post('/pacientes/' . $p['id'] . '/prontuario/evolucao', $this->evo(['encounter_date' => '10/01/2026']));
        $e = $this->db->one("SELECT * FROM clinical_entries WHERE kind='evolucao'");
        $this->assertSame('2026-01-10', $e['encounter_date']);
        $res = $this->post('/prontuario/' . $e['id'] . '/revisar', $this->evo(['encounter_date' => '', 'conduta' => 'Nova conduta']));
        $this->assertSame(302, $res->status);
        $cur = $this->db->one("SELECT * FROM clinical_entries WHERE kind='evolucao' AND superseded_at IS NULL");
        $this->assertSame('2026-01-10', $cur['encounter_date']);
    }

    public function testBlankEncounterDateOnCreateIsToday(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        $this->assertSame(302, $this->post('/pacientes/' . $p['id'] . '/prontuario/evolucao', $this->evo(['encounter_date' => '']))->status);
        $this->assertSame(date('Y-m-d'), $this->db->one("SELECT encounter_date FROM clinical_entries WHERE kind='evolucao'")['encounter_date']);
    }

    public function testSummaryCardForDoctor(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->loginAs($m);
        $this->post('/pacientes/' . $p['id'] . '/prontuario/evolucao', $this->evo(['diagnostico' => 'Obesidade <b>grau</b>']));
        $body = $this->get('/pacientes/' . $p['id'])->body;
        $this->assertStringContainsString('cartao-prontuario', $body);
        $this->assertStringContainsString('Obesidade &lt;b&gt;grau', $body);
    }
}
