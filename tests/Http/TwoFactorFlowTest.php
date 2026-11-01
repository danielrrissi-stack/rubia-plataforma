<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Auth\Totp;
use App\Core\App;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DbTestCase;

final class TwoFactorFlowTest extends DbTestCase
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

    private function login(array $u): Response
    {
        return $this->post('/login', ['email' => $u['email'], 'senha' => 'senha-teste-123']);
    }

    private function row(int $id): array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function testStaffWithoutTotpIsForcedToSetup(): void
    {
        $this->login($this->makeUser('secretaria'));
        $res = $this->get('/');
        $this->assertSame(302, $res->status);
        $this->assertSame('/2fa/configurar', $res->headers['Location']);
    }

    public function testFamilyWithoutTotpIsNotForced(): void
    {
        $this->login($this->makeUser('responsavel'));
        $res = $this->get('/');
        $this->assertSame(302, $res->status);
        $this->assertSame('/portal', $res->headers['Location']);
    }

    public function testSetupPageReachableAndLogoutExempt(): void
    {
        $this->login($this->makeUser('medico'));
        $res = $this->get('/2fa/configurar');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('data-otpauth="otpauth://totp/', $res->body);
        $this->assertStringContainsString('/assets/vendor/qrcode.min.js', $res->body);
        $this->assertStringNotContainsString('<script>', $res->body);
        $out = $this->post('/logout');
        $this->assertSame('/login', $out->headers['Location']);
    }

    public function testSetupConfirmsValidCodeAndEnables(): void
    {
        $u = $this->makeUser('medico');
        $this->login($u);
        $this->get('/2fa/configurar');
        $secret = Session::get('totp_setup_secret')['secret'] ?? null;
        $this->assertIsString($secret);

        $bad = $this->post('/2fa/configurar', ['codigo' => '000000']);
        $this->assertSame(200, $bad->status);
        $this->assertSame(0, (int) $this->row((int) $u['id'])['totp_enabled']);

        $ok = $this->post('/2fa/configurar', ['codigo' => Totp::code($secret, time())]);
        $this->assertSame(302, $ok->status);
        $this->assertSame('/', $ok->headers['Location']);
        $row = $this->row((int) $u['id']);
        $this->assertSame(1, (int) $row['totp_enabled']);
        $this->assertSame($secret, $row['totp_secret']);
        $this->assertNull(Session::get('totp_setup_secret'));
        $audit = $this->db->one("SELECT after_json FROM audit_log WHERE action='config' AND entity='users'");
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($secret, (string) $audit['after_json']);
        $this->assertSame(200, $this->get('/')->status);
    }

    public function testLoginWithTotpRequiresCode(): void
    {
        $secret = Totp::generateSecret();
        $u = $this->makeUser('medico', ['totp_enabled' => 1, 'totp_secret' => $secret]);
        $res = $this->login($u);
        $this->assertSame('/2fa', $res->headers['Location']);
        $this->assertSame(200, $this->get('/2fa')->status);
        $this->assertSame('/login', $this->get('/')->headers['Location']);

        $ok = $this->post('/2fa', ['codigo' => Totp::code($secret, time())]);
        $this->assertSame(302, $ok->status);
        $this->assertSame('/', $ok->headers['Location']);
        $this->assertNull(Session::get('pending_2fa_user'));
        $this->assertSame(200, $this->get('/')->status);
    }

    public function testTwoFactorWithoutPendingRedirectsToLogin(): void
    {
        $this->assertSame('/login', $this->get('/2fa')->headers['Location']);
        $this->assertSame('/login', $this->post('/2fa', ['codigo' => '123456'])->headers['Location']);
    }

    public function testFiveWrongCodesClearPendingAndAudit(): void
    {
        $secret = Totp::generateSecret();
        $u = $this->makeUser('medico', ['totp_enabled' => 1, 'totp_secret' => $secret]);
        $this->login($u);
        for ($i = 0; $i < 4; $i++) {
            $res = $this->post('/2fa', ['codigo' => '000000']);
            $this->assertSame(200, $res->status);
        }
        $res = $this->post('/2fa', ['codigo' => '000000']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/login', $res->headers['Location']);
        $this->assertNull(Session::get('pending_2fa_user'));
        $n = $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='login_failed' AND after_json LIKE '%totp%'");
        $this->assertSame(5, (int) $n['c']);
        $this->assertSame('/login', $this->post('/2fa', ['codigo' => '000000'])->headers['Location']);
    }

    public function testWrongCodesLockAccountAcrossRelogin(): void
    {
        $secret = Totp::generateSecret();
        $u = $this->makeUser('medico', ['totp_enabled' => 1, 'totp_secret' => $secret]);
        $this->login($u);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/2fa', ['codigo' => '000000']);
        }
        $res = $this->login($u);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Conta bloqueada', $res->body);
        $this->assertNull(Session::get('pending_2fa_user'));
    }

    public function testCorrectPasswordDoesNotResetTotpFailures(): void
    {
        $secret = Totp::generateSecret();
        $u = $this->makeUser('medico', ['totp_enabled' => 1, 'totp_secret' => $secret]);
        for ($round = 0; $round < 2; $round++) {
            $this->login($u);
            $this->post('/2fa', ['codigo' => '000000']);
            $this->post('/2fa', ['codigo' => '000000']);
        }
        $this->assertSame(4, (int) $this->row((int) $u['id'])['failed_logins']);
    }

    public function testCorrectCodeRefusedWhileLocked(): void
    {
        $secret = Totp::generateSecret();
        $u = $this->makeUser('medico', ['totp_enabled' => 1, 'totp_secret' => $secret]);
        $this->login($u);
        $this->db->run('UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?', [$u['id']]);
        $res = $this->post('/2fa', ['codigo' => Totp::code($secret, time())]);
        $this->assertSame('/login', $res->headers['Location']);
        $this->assertNull(Session::get('pending_2fa_user'));
        $this->assertNull(Session::get('uid'));
        $this->assertSame('/login', $this->get('/')->headers['Location']);
    }

    public function testReconfigureRequiresCurrentCode(): void
    {
        $old = Totp::generateSecret();
        $u = $this->makeUser('medico', ['totp_enabled' => 1, 'totp_secret' => $old]);
        \App\Core\Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));

        $page = $this->get('/2fa/configurar');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('name="codigo_atual"', $page->body);
        $this->assertStringNotContainsString('data-otpauth', $page->body);
        $this->assertNull(Session::get('totp_setup_secret'));

        // Sem o codigo atual, um "codigo" novo nao troca o segredo (e conta como falha).
        $this->post('/2fa/configurar', ['codigo' => '123456']);
        $this->assertSame($old, $this->row((int) $u['id'])['totp_secret']);
        $this->assertSame(1, (int) $this->row((int) $u['id'])['failed_logins']);

        $bad = $this->post('/2fa/configurar', ['codigo_atual' => '000000']);
        $this->assertSame(200, $bad->status);
        $this->assertStringContainsString('Código atual inválido', $bad->body);
        $this->assertSame(2, (int) $this->row((int) $u['id'])['failed_logins']);
        $this->assertStringNotContainsString('data-otpauth', $bad->body);

        $ok = $this->post('/2fa/configurar', ['codigo_atual' => Totp::code($old, time())]);
        $this->assertSame(302, $ok->status);
        $this->assertSame('/2fa/configurar', $ok->headers['Location']);
        $setup = $this->get('/2fa/configurar');
        $this->assertStringContainsString('data-otpauth', $setup->body);
        $new = Session::get('totp_setup_secret')['secret'];
        $this->assertNotSame($old, $new);

        $done = $this->post('/2fa/configurar', ['codigo' => Totp::code($new, time())]);
        $this->assertSame('/', $done->headers['Location']);
        $this->assertSame($new, $this->row((int) $u['id'])['totp_secret']);
    }

    public function testReconfigureWrongCurrentCodesLockAccount(): void
    {
        $old = Totp::generateSecret();
        $u = $this->makeUser('medico', ['totp_enabled' => 1, 'totp_secret' => $old]);
        \App\Core\Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
        $res = null;
        for ($i = 0; $i < 5; $i++) {
            $res = $this->post('/2fa/configurar', ['codigo_atual' => '000000']);
        }
        $this->assertSame(302, $res->status);
        $this->assertSame('/login', $res->headers['Location']);
        $this->assertNull(Session::get('uid'));
        $this->assertNotNull($this->row((int) $u['id'])['locked_until']);
    }

    public function testSetupSecretIsBoundToUserAndClearedOnLogout(): void
    {
        $a = $this->makeUser('medico');
        $b = $this->makeUser('secretaria');
        $this->login($a);
        $this->get('/2fa/configurar');
        $secretA = Session::get('totp_setup_secret')['secret'];
        $this->post('/logout');
        $this->assertNull(Session::get('totp_setup_secret'));
        $this->login($b);
        $this->get('/2fa/configurar');
        $secretB = Session::get('totp_setup_secret')['secret'];
        $this->assertNotSame($secretA, $secretB);
        $this->assertStringNotContainsString(implode(' ', str_split($secretA, 4)), $this->get('/2fa/configurar')->body);
    }
}
