<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\TokenService;
use Tests\DbTestCase;

final class TokenServiceTest extends DbTestCase
{
    public function testConsumeReturnsUserIdOnlyOnce(): void
    {
        $u = $this->makeUser('medico');
        $svc = new TokenService($this->db);
        $token = $svc->issue((int) $u['id'], 'reset', 60);
        $this->assertSame(64, strlen($token));
        $this->assertSame((int) $u['id'], $svc->consume($token, 'reset'));
        $this->assertNull($svc->consume($token, 'reset'));
    }

    public function testStoresOnlyHash(): void
    {
        $u = $this->makeUser('medico');
        $token = (new TokenService($this->db))->issue((int) $u['id'], 'invite', 60);
        $row = $this->db->one('SELECT * FROM user_tokens WHERE user_id = ?', [$u['id']]);
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
    }

    public function testExpiredTokenReturnsNull(): void
    {
        $u = $this->makeUser('medico');
        $svc = new TokenService($this->db);
        $token = $svc->issue((int) $u['id'], 'reset', -5);
        $this->assertNull($svc->consume($token, 'reset'));
    }

    public function testWrongPurposeReturnsNull(): void
    {
        $u = $this->makeUser('medico');
        $svc = new TokenService($this->db);
        $token = $svc->issue((int) $u['id'], 'reset', 60);
        $this->assertNull($svc->consume($token, 'invite'));
        $this->assertSame((int) $u['id'], $svc->consume($token, 'reset'));
    }
}
