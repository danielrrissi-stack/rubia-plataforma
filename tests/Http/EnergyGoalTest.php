<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class EnergyGoalTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
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

    private function setup2(): array
    {
        $nutri = $this->makeUser('nutricionista');
        $p = $this->makePatient(['birth_date' => '2014-05-10', 'sex' => 'M', 'responsible_professional_id' => $nutri['id'], 'created_by' => $nutri['id']]);
        $this->db->insert('professional_access', ['patient_id' => $p['id'], 'professional_id' => $nutri['id'], 'granted_by' => $nutri['id']]);
        $this->loginAs($nutri);
        return [$nutri, $p];
    }

    private function body(array $over = []): array
    {
        return $over + ['peso' => '40,0', 'altura' => '140,0', 'formula' => 'fao_oms_1985', 'fator' => '1.55', 'notas' => 'ok'];
    }

    public function testSaveSupersedesPrevious(): void
    {
        [, $p] = $this->setup2();
        $url = '/pacientes/' . $p['id'] . '/energia';
        $this->assertSame(302, $this->post($url, $this->body())->status);
        $this->assertSame(302, $this->post($url, $this->body(['peso' => '42,0']))->status);
        $rows = $this->db->all('SELECT * FROM energy_goals WHERE patient_id = ? ORDER BY id', [$p['id']]);
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]['superseded_at']);
        $this->assertNull($rows[1]['superseded_at']);
        $tmb = (int) round(17.5 * 42 + 651);
        $this->assertSame($tmb, (int) $rows[1]['tmb_kcal']);
        $this->assertSame((int) round($tmb * 1.55), (int) $rows[1]['get_kcal']);
        $this->assertSame('fao_oms_1985', $rows[1]['formula']);
        $this->assertStringContainsString((string) $rows[1]['get_kcal'], $this->get($url)->body);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE entity='energy_goals' AND action='create' AND entity_id = ?", [$rows[1]['id']])['c']);
    }

    public function testInvalidFactorAndUnavailableFormula(): void
    {
        [, $p] = $this->setup2();
        $url = '/pacientes/' . $p['id'] . '/energia';
        $res = $this->post($url, $this->body(['fator' => '3']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('entre 1,0 e 2,5', $res->body);
        $res = $this->post($url, $this->body(['formula' => 'mifflin']));
        $this->assertSame(200, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM energy_goals')['c']);
    }

    public function testSecretaryForbidden(): void
    {
        $sec = $this->makeUser('secretaria');
        $p = $this->makePatient();
        $this->loginAs($sec);
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/energia')->status);
        $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/energia', $this->body())->status);
    }

    public function testSummaryCardShowsGoal(): void
    {
        [, $p] = $this->setup2();
        $this->post('/pacientes/' . $p['id'] . '/energia', $this->body());
        $g = $this->db->one('SELECT get_kcal FROM energy_goals');
        $this->assertStringContainsString($g['get_kcal'] . ' kcal', $this->get('/pacientes/' . $p['id'])->body);
    }
}
