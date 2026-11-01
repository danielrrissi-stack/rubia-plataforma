<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class PatientCrudTest extends DbTestCase
{
    private function get(string $path, array $q = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $q));
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

    private function term(): void
    {
        $this->db->run('UPDATE consent_terms SET active = 0');
        $this->db->run("DELETE FROM consent_terms WHERE version = '1.0'");
        $this->db->insert('consent_terms', ['version' => '1.0', 'body_html' => '<p>Termo LGPD</p>', 'active' => 1]);
    }

    private function form(array $over = []): array
    {
        return $over + [
            'nome' => 'Maria Souza', 'data_nascimento' => '05/03/2019', 'sexo' => 'F',
            'telefone' => '11 99999-0000', 'profissional' => '',
        ];
    }

    public function testSecretaryCreatesPatientWithBrDate(): void
    {
        $med = $this->makeUser('medico', ['is_admin' => 1]);
        $this->loginAs($this->makeUser('secretaria'));
        $res = $this->post('/pacientes/novo', $this->form(['cpf' => '52998224725']));
        $this->assertSame(302, $res->status);
        $p = $this->db->one("SELECT * FROM patients WHERE full_name = 'Maria Souza'");
        $this->assertSame('2019-03-05', $p['birth_date']);
        $this->assertSame('529.982.247-25', $p['cpf']);
        $this->assertSame((int) $med['id'], (int) $p['responsible_professional_id']);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE entity='patients' AND action='create'"));
    }

    public function testFutureBirthDateIsRejectedAndValuesKept(): void
    {
        $this->makeUser('medico', ['is_admin' => 1]);
        $this->loginAs($this->makeUser('secretaria'));
        $future = date('d/m/Y', strtotime('+2 days'));
        $res = $this->post('/pacientes/novo', $this->form(['data_nascimento' => $future, 'nome' => 'Fulana Mantida']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Data de nascimento não pode ser futura', $res->body);
        $this->assertStringContainsString('Fulana Mantida', $res->body);
        $this->assertNull($this->db->one("SELECT 1 FROM patients WHERE full_name = 'Fulana Mantida'"));
    }

    public function testInvalidCpfRejected(): void
    {
        $this->makeUser('medico', ['is_admin' => 1]);
        $this->loginAs($this->makeUser('secretaria'));
        $res = $this->post('/pacientes/novo', $this->form(['cpf' => '111.111.111-11']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('CPF inválido', $res->body);
    }

    public function testNutritionistWithoutGrantGets403ButListIsFiltered(): void
    {
        $nutri = $this->makeUser('nutricionista');
        $granted = $this->makePatient(['full_name' => 'Concedida Um']);
        $other = $this->makePatient(['full_name' => 'Escondida Dois']);
        $this->db->insert('professional_access', [
            'patient_id' => $granted['id'], 'professional_id' => $nutri['id'], 'granted_by' => $granted['created_by'],
        ]);
        $this->loginAs($nutri);
        $this->assertSame(403, $this->get('/pacientes/' . $other['id'])->status);
        $this->assertSame(200, $this->get('/pacientes/' . $granted['id'])->status);
        $list = $this->get('/pacientes');
        $this->assertStringContainsString('Concedida Um', $list->body);
        $this->assertStringNotContainsString('Escondida Dois', $list->body);
        $this->assertSame(403, $this->get('/pacientes/novo')->status);
    }

    public function testSummaryHidesContactFromNutritionistAndAuditsView(): void
    {
        $nutri = $this->makeUser('nutricionista');
        $p = $this->makePatient(['phone' => '11988887777']);
        $this->db->insert('professional_access', [
            'patient_id' => $p['id'], 'professional_id' => $nutri['id'], 'granted_by' => $p['created_by'],
        ]);
        $this->loginAs($nutri);
        $res = $this->get('/pacientes/' . $p['id']);
        $this->assertStringNotContainsString('11988887777', $res->body);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE action='view' AND entity='patients' AND patient_id = ?", [$p['id']]));
    }

    public function testGuardianWithoutConsentRedirectedToConsent(): void
    {
        $this->term();
        $g = $this->makeUser('responsavel');
        $p = $this->makePatient();
        Container::guardians()->link((int) $p['id'], (int) $g['id'], 'mãe', (int) $p['created_by']);
        $this->loginAs($g);
        $res = $this->get('/');
        $this->assertSame(302, $res->status);
        $this->assertSame('/consentimento', $res->headers['Location'] ?? null);
        $page = $this->get('/consentimento');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Termo LGPD', $page->body);
        $res = $this->post('/consentimento', ['term_version' => '1.0', 'patient_id' => (string) $p['id'], 'aceito' => '1']);
        $this->assertSame('/', $res->headers['Location'] ?? null);
        $this->assertSame(200, $this->get('/')->status);
    }

    public function testConsentRejectsUnrelatedPatient(): void
    {
        $this->term();
        $g = $this->makeUser('responsavel');
        $p = $this->makePatient();
        $other = $this->makePatient();
        Container::guardians()->link((int) $p['id'], (int) $g['id'], 'mãe', (int) $p['created_by']);
        $this->loginAs($g);
        $this->post('/consentimento', ['term_version' => '1.0', 'patient_id' => (string) $other['id'], 'aceito' => '1']);
        $this->assertNull($this->db->one('SELECT 1 FROM consents WHERE patient_id = ?', [$other['id']]));
    }

    public function testExistingGuardianReusedForSecondChild(): void
    {
        $this->loginAs($this->makeUser('secretaria'));
        $p1 = $this->makePatient();
        $p2 = $this->makePatient();
        $data = ['nome' => 'Mãe Ana', 'email' => 'Ana@Example.test', 'parentesco' => 'mãe'];
        $this->assertSame(302, $this->post('/pacientes/' . $p1['id'] . '/responsaveis', $data)->status);
        $this->db->run("UPDATE users SET password_hash = 'x' WHERE email = 'ana@example.test'");
        $this->assertSame(302, $this->post('/pacientes/' . $p2['id'] . '/responsaveis', $data)->status);
        $users = $this->db->all("SELECT * FROM users WHERE email = 'ana@example.test'");
        $this->assertCount(1, $users);
        $this->assertSame('responsavel', $users[0]['role']);
        $this->assertCount(2, $this->db->all('SELECT * FROM guardian_links WHERE user_id = ?', [$users[0]['id']]));
        $this->assertCount(1, $this->db->all('SELECT * FROM mail_queue')); // ja tem senha: sem novo convite
        $mail = $this->db->one('SELECT * FROM mail_queue');
        $this->assertSame('Acesso ao acompanhamento — Dra. Rubia', $mail['subject']);
    }

    public function testGuardianEmailOfOtherRoleRejectedAndDuplicateLinkPrevented(): void
    {
        $this->loginAs($this->makeUser('secretaria'));
        $p = $this->makePatient();
        $this->makeUser('nutricionista', ['email' => 'nutri@example.test']);
        $res = $this->post('/pacientes/' . $p['id'] . '/responsaveis', ['nome' => 'X', 'email' => 'nutri@example.test', 'parentesco' => 'pai']);
        $this->assertStringContainsString('Este e-mail já pertence a outro tipo de conta.', $res->body);
        $d = ['nome' => 'Pai', 'email' => 'pai@example.test', 'parentesco' => 'pai'];
        $this->post('/pacientes/' . $p['id'] . '/responsaveis', $d);
        $this->post('/pacientes/' . $p['id'] . '/responsaveis', $d);
        $this->assertCount(1, $this->db->all('SELECT * FROM guardian_links WHERE patient_id = ? AND active = 1', [$p['id']]));
    }

    public function testUnlinkGuardian(): void
    {
        $this->loginAs($this->makeUser('secretaria'));
        $p = $this->makePatient();
        $g = $this->makeUser('responsavel');
        $id = Container::guardians()->link((int) $p['id'], (int) $g['id'], 'pai', (int) $p['created_by']);
        $this->post('/responsaveis/' . $id . '/remover');
        $row = $this->db->one('SELECT * FROM guardian_links WHERE id = ?', [$id]);
        $this->assertSame(0, (int) $row['active']);
        $this->assertNotNull($row['ended_at']);
    }

    public function testAdultAccessOnlyForAdults(): void
    {
        $this->loginAs($this->makeUser('secretaria'));
        $child = $this->makePatient(['birth_date' => '2015-01-01']);
        $adult = $this->makePatient(['birth_date' => '1990-01-01']);
        $this->post('/pacientes/' . $child['id'] . '/acesso', ['email' => 'kid@example.test']);
        $this->assertNull($this->db->one("SELECT 1 FROM users WHERE email = 'kid@example.test'"));
        $this->post('/pacientes/' . $adult['id'] . '/acesso', ['email' => 'adulto@example.test']);
        $u = $this->db->one("SELECT * FROM users WHERE email = 'adulto@example.test'");
        $this->assertSame('paciente', $u['role']);
        $this->assertNull($u['password_hash']);
        $this->assertSame((int) $u['id'], (int) $this->db->one('SELECT user_id FROM patients WHERE id = ?', [$adult['id']])['user_id']);
        $this->assertCount(1, $this->db->all('SELECT * FROM mail_queue'));
    }
}
