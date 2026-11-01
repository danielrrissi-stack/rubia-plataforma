<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Audit\AuditLog;
use App\Auth\AuthService;
use App\Core\Request;
use App\Core\Session;
use App\Users\UserRepository;
use Tests\DbTestCase;

final class AuthServiceTest extends DbTestCase
{
    private int $now;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = 1_800_000_000;
        $this->auth = new AuthService(
            $this->db,
            new UserRepository($this->db),
            new AuditLog($this->db),
            new Session(),
            fn (): int => $this->now,
        );
    }

    private function req(): Request
    {
        return new Request('POST', '/login', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
    }

    public function testCorrectPasswordSucceedsCaseInsensitiveEmail(): void
    {
        $u = $this->makeUser('medico', ['email' => 'dra@example.test']);
        $r = $this->auth->attempt('  DRA@Example.test ', 'senha-teste-123', $this->req());
        $this->assertTrue($r->ok);
        $this->assertFalse($r->needsTotp);
        $this->assertSame((int) $u['id'], $this->auth->user()['id']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='login' AND user_id=?", [$u['id']])['c']);
    }

    public function testFiveFailuresLockAccount(): void
    {
        $u = $this->makeUser('medico');
        for ($i = 0; $i < 5; $i++) {
            $r = $this->auth->attempt($u['email'], 'errada', $this->req());
            $this->assertFalse($r->ok);
            $this->assertSame('E-mail ou senha inválidos.', $r->error);
        }
        $r = $this->auth->attempt($u['email'], 'senha-teste-123', $this->req());
        $this->assertFalse($r->ok);
        $this->assertSame('Conta bloqueada temporariamente. Tente novamente em 15 minutos.', $r->error);
        $this->assertSame(6, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='login_failed' AND user_id=?", [$u['id']])['c']);
    }

    public function testInactiveUserGetsGenericError(): void
    {
        $u = $this->makeUser('medico', ['active' => 0]);
        $r = $this->auth->attempt($u['email'], 'senha-teste-123', $this->req());
        $this->assertFalse($r->ok);
        $this->assertSame('E-mail ou senha inválidos.', $r->error);
    }

    public function testUnknownEmailLogsFailureWithNullUser(): void
    {
        $r = $this->auth->attempt('ninguem@example.test', 'x', $this->req());
        $this->assertFalse($r->ok);
        $row = $this->db->one("SELECT * FROM audit_log WHERE action='login_failed' ORDER BY id DESC LIMIT 1");
        $this->assertNull($row['user_id']);
        $this->assertStringNotContainsString('senha', (string) $row['after_json']);
    }

    public function testSuccessResetsFailedCounter(): void
    {
        $u = $this->makeUser('medico');
        $this->auth->attempt($u['email'], 'errada', $this->req());
        $this->auth->attempt($u['email'], 'senha-teste-123', $this->req());
        $this->assertSame(0, (int) $this->db->one('SELECT failed_logins f FROM users WHERE id=?', [$u['id']])['f']);
    }

    public function testTotpUserIsNotFullyLoggedIn(): void
    {
        $u = $this->makeUser('medico', ['totp_enabled' => 1]);
        $r = $this->auth->attempt($u['email'], 'senha-teste-123', $this->req());
        $this->assertTrue($r->ok);
        $this->assertTrue($r->needsTotp);
        $this->assertNull($this->auth->user());
        $this->assertSame((int) $u['id'], Session::get('pending_2fa_user'));
    }

    public function testPendingTotpClearsPreviousFullLogin(): void
    {
        $a = $this->makeUser('medico');
        $b = $this->makeUser('medico', ['totp_enabled' => 1]);
        $this->auth->attempt($a['email'], 'senha-teste-123', $this->req());
        $this->assertNotNull($this->auth->user());
        $this->auth->attempt($b['email'], 'senha-teste-123', $this->req());
        $this->assertNull(Session::get('uid'));
        $this->assertNull(Session::get('last_activity'));
        $this->assertSame((int) $b['id'], Session::get('pending_2fa_user'));
    }

    public function testCompleteLoginRotatesCsrfToken(): void
    {
        $u = $this->makeUser('medico');
        $before = \App\Core\Csrf::token();
        $this->auth->completeLogin((int) $u['id'], $this->req());
        $this->assertNotSame($before, \App\Core\Csrf::token());
    }

    public function testStaffIdleTimeoutAfter30Minutes(): void
    {
        $u = $this->makeUser('medico');
        $this->auth->attempt($u['email'], 'senha-teste-123', $this->req());
        $this->now += 29 * 60;
        $this->assertNotNull($this->auth->user());
        $this->now += 31 * 60;
        $this->assertNull($this->auth->user());
        $this->assertSame('Sua sessão expirou por inatividade.', Session::flash('erro'));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='logout'")['c']);
    }

    public function testFamilyAllowedUpTo120Minutes(): void
    {
        $u = $this->makeUser('responsavel');
        $this->auth->attempt($u['email'], 'senha-teste-123', $this->req());
        $this->now += 100 * 60;
        $this->assertNotNull($this->auth->user());
        $this->now += 121 * 60;
        $this->assertNull($this->auth->user());
    }

    public function testLogout(): void
    {
        $u = $this->makeUser('medico');
        $this->auth->attempt($u['email'], 'senha-teste-123', $this->req());
        $this->auth->logout($this->req());
        $this->assertNull($this->auth->user());
    }
}
