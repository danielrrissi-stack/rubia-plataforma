<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class DashboardTest extends DbTestCase
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

    private function pendingPatient(): array
    {
        $p = $this->makePatient(['birth_date' => '2000-01-01', 'full_name' => 'Jovem Maior']);
        $g = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $p['created_by']]);
        return $p;
    }

    public function testDoctorSeesMajorityBlock(): void
    {
        $this->pendingPatient();
        $this->loginAs($this->makeUser('medico'));
        $res = $this->get('/');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Decisões de maioridade pendentes', $res->body);
        $this->assertStringContainsString('Jovem Maior', $res->body);
    }

    public function testSecretaryDoesNotSeeMajorityBlock(): void
    {
        $this->pendingPatient();
        $this->loginAs($this->makeUser('secretaria'));
        $res = $this->get('/');
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('Decisões de maioridade pendentes', $res->body);
        $this->assertStringContainsString('Novo paciente', $res->body);
        $this->assertSame(403, $this->get('/maioridade')->status);
    }

    public function testFamilyRedirectedToPortal(): void
    {
        $this->loginAs($this->makeUser('responsavel'));
        $res = $this->get('/');
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal', $res->headers['Location']);
    }

    public function testNutritionistSeesOnlyGrantedPatients(): void
    {
        $med = $this->makeUser('medico');
        $nut = $this->makeUser('nutricionista');
        $a = $this->makePatient(['full_name' => 'Liberado Um']);
        $this->makePatient(['full_name' => 'Oculto Dois']);
        Container::accessGrants()->grant((int) $a['id'], (int) $nut['id'], (int) $med['id']);
        $this->loginAs($nut);
        $res = $this->get('/');
        $this->assertStringContainsString('Liberado Um', $res->body);
        $this->assertStringNotContainsString('Oculto Dois', $res->body);
    }

    public function testDoctorGrantsAndRevokesViaHttp(): void
    {
        $med = $this->makeUser('medico');
        $nut = $this->makeUser('nutricionista');
        $sec = $this->makeUser('secretaria');
        $p = $this->makePatient();
        $this->loginAs($med);
        $this->assertSame(302, $this->post('/pacientes/' . $p['id'] . '/acesso-profissionais', ['profissional' => (string) $sec['id']])->status);
        $this->assertSame([], Container::accessGrants()->activeFor((int) $p['id']));
        $this->post('/pacientes/' . $p['id'] . '/acesso-profissionais', ['profissional' => (string) $nut['id']]);
        $grants = Container::accessGrants()->activeFor((int) $p['id']);
        $this->assertCount(1, $grants);
        $this->assertStringContainsString($nut['name'], $this->get('/pacientes/' . $p['id'] . '/acesso-profissionais')->body);
        $this->post('/acesso-profissionais/' . $grants[0]['id'] . '/revogar');
        $this->assertSame([], Container::accessGrants()->activeFor((int) $p['id']));
    }

    public function testSecretaryCannotManageGrants(): void
    {
        $p = $this->makePatient();
        $this->loginAs($this->makeUser('secretaria'));
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/acesso-profissionais')->status);
    }

    public function testMajorityDecisionViaHttp(): void
    {
        $p = $this->pendingPatient();
        $this->loginAs($this->makeUser('medico'));
        $this->assertSame(200, $this->get('/maioridade')->status);
        $res = $this->post('/pacientes/' . $p['id'] . '/maioridade', ['decisao' => 'encerrar', 'observacoes' => 'x']);
        $this->assertSame(302, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM guardian_links WHERE patient_id = ? AND active = 1', [$p['id']])['c']);
    }
}
