<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class PortalTest extends DbTestCase
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

    private function child(array $guardian, string $name, string $birth = '2016-05-10'): array
    {
        $p = $this->makePatient(['full_name' => $name, 'birth_date' => $birth, 'sex' => 'F']);
        $this->db->insert('guardian_links', [
            'patient_id' => $p['id'], 'user_id' => $guardian['id'], 'relationship' => 'mãe', 'created_by' => $p['created_by'],
        ]);
        return $p;
    }

    public function testGuardianWithTwoChildrenChoosesPatient(): void
    {
        $g = $this->makeUser('responsavel');
        $a = $this->child($g, 'Ana Souza');
        $b = $this->child($g, 'Beto Souza');
        $this->loginAs($g);
        $res = $this->get('/portal');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Ana', $res->body);
        $this->assertStringContainsString('Beto', $res->body);
        $this->assertStringContainsString('/portal/p/' . $a['id'], $res->body);
        $this->assertStringContainsString('/portal/p/' . $b['id'], $res->body);
    }

    public function testGuardianWithOneChildGoesStraightToChild(): void
    {
        $g = $this->makeUser('responsavel');
        $a = $this->child($g, 'Ana Souza');
        $this->loginAs($g);
        $res = $this->get('/portal');
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $a['id'], $res->headers['Location']);
        $home = $this->get('/portal/p/' . $a['id']);
        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('Olá! Bem-vindo ao acompanhamento', $home->body);
        $this->assertStringContainsString('Crescimento', $home->body);
        $this->assertStringNotContainsString('Alimentação', $home->body);
    }

    public function testOtherChildIsForbiddenAndAudited(): void
    {
        $g = $this->makeUser('responsavel');
        $this->child($g, 'Ana Souza');
        $other = $this->makePatient(['birth_date' => '2016-01-01']);
        $this->loginAs($g);
        foreach (['/crescimento', '/medidas/nova', ''] as $suffix) {
            $this->assertSame(403, $this->get('/portal/p/' . $other['id'] . $suffix)->status);
        }
        $n = (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='denied' AND user_id = ?", [$g['id']])['c'];
        $this->assertGreaterThanOrEqual(3, $n);
        $res = $this->post('/portal/p/' . $other['id'] . '/medidas/nova', ['data_medida' => date('d/m/Y'), 'peso' => '30']);
        $this->assertSame(403, $res->status);
    }

    public function testRegistersMeasurementFromFamily(): void
    {
        $g = $this->makeUser('responsavel');
        $a = $this->child($g, 'Ana Souza');
        $this->loginAs($g);
        $this->assertSame(200, $this->get('/portal/p/' . $a['id'] . '/medidas/nova')->status);
        $res = $this->post('/portal/p/' . $a['id'] . '/medidas/nova', ['data_medida' => date('d/m/Y'), 'peso' => '28,4']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $a['id'] . '/crescimento', $res->headers['Location']);
        $row = $this->db->one('SELECT * FROM measurements WHERE patient_id = ?', [$a['id']]);
        $this->assertSame('familia', $row['source']);
        $this->assertEqualsWithDelta(28.4, (float) $row['weight_kg'], 0.001);
    }

    public function testEmptyMeasurementIsRejected(): void
    {
        $g = $this->makeUser('responsavel');
        $a = $this->child($g, 'Ana Souza');
        $this->loginAs($g);
        $res = $this->post('/portal/p/' . $a['id'] . '/medidas/nova', ['data_medida' => date('d/m/Y')]);
        $this->assertSame(200, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM measurements')['c']);
    }

    public function testGrowthPageUsesPlainLanguage(): void
    {
        $g = $this->makeUser('responsavel');
        $a = $this->child($g, 'Ana Souza');
        $this->loginAs($g);
        $this->post('/portal/p/' . $a['id'] . '/medidas/nova', ['data_medida' => date('d/m/Y'), 'peso' => '60', 'altura' => '130']);
        $res = $this->get('/portal/p/' . $a['id'] . '/crescimento');
        $this->assertSame(200, $res->status);
        foreach (['Evolução', 'Diagnóstico', 'escore Z', 'Escore Z'] as $bad) {
            $this->assertStringNotContainsString($bad, $res->body);
        }
        $this->assertStringContainsString('faixa esperada', $res->body);
        $this->assertStringContainsString('/pacientes/' . $a['id'] . '/curvas.json', $res->body);
    }

    public function testAdultPatientGoesStraightToOwnRecord(): void
    {
        $u = $this->makeUser('paciente');
        $p = $this->makePatient(['birth_date' => '1985-02-03', 'user_id' => $u['id']]);
        $this->loginAs($u);
        $res = $this->get('/portal');
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal/p/' . $p['id'], $res->headers['Location']);
        $g = $this->get('/portal/p/' . $p['id'] . '/crescimento');
        $this->assertSame(200, $g->status);
    }

    public function testStaffIsRedirectedAway(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $res = $this->get('/portal');
        $this->assertSame(302, $res->status);
        $this->assertSame('/', $res->headers['Location']);
    }

    public function testChangePassword(): void
    {
        $g = $this->makeUser('responsavel');
        $this->loginAs($g);
        $this->assertSame(200, $this->get('/portal/conta')->status);
        $bad = $this->post('/portal/conta/senha', ['atual' => 'errada', 'nova' => 'novasenha12345', 'confirmacao' => 'novasenha12345']);
        $this->assertSame(200, $bad->status);
        $short = $this->post('/portal/conta/senha', ['atual' => 'senha-teste-123', 'nova' => 'curta', 'confirmacao' => 'curta']);
        $this->assertSame(200, $short->status);
        $ok = $this->post('/portal/conta/senha', ['atual' => 'senha-teste-123', 'nova' => 'novasenha12345', 'confirmacao' => 'novasenha12345']);
        $this->assertSame(302, $ok->status);
        $hash = $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$g['id']])['password_hash'];
        $this->assertTrue(password_verify('novasenha12345', $hash));
    }

    public function testServiceWorkerCachesOnlyAssets(): void
    {
        $sw = (string) file_get_contents(dirname(__DIR__, 2) . '/public/sw.js');
        $this->assertStringNotContainsString('/portal/p/', $sw);
        $this->assertStringContainsString('/assets/', $sw);
        $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/manifest.json'), true);
        $this->assertSame('/portal', $m['start_url']);
        $this->assertSame('standalone', $m['display']);
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/icons/icon-192.png');
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/assets/icons/icon-512.png');
    }
}
