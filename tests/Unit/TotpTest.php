<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Base32;
use App\Auth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private string $secret;

    protected function setUp(): void
    {
        $this->secret = Base32::encode('12345678901234567890');
    }

    public function testRfc6238Vectors(): void
    {
        $this->assertSame('94287082', Totp::code($this->secret, 59, 8));
        $this->assertSame('07081804', Totp::code($this->secret, 1111111109, 8));
        $this->assertSame('081804', Totp::code($this->secret, 1111111109));
    }

    public function testVerifyWindow(): void
    {
        $t = 1111111109;
        $this->assertTrue(Totp::verify($this->secret, Totp::code($this->secret, $t), $t));
        $this->assertTrue(Totp::verify($this->secret, Totp::code($this->secret, $t - 30), $t));
        $this->assertTrue(Totp::verify($this->secret, Totp::code($this->secret, $t + 30), $t));
        $this->assertFalse(Totp::verify($this->secret, Totp::code($this->secret, $t - 60), $t));
        $this->assertFalse(Totp::verify($this->secret, Totp::code($this->secret, $t + 60), $t));
    }

    public function testVerifyRejectsMalformed(): void
    {
        $this->assertFalse(Totp::verify($this->secret, '', 59));
        $this->assertFalse(Totp::verify($this->secret, 'abcdef', 59));
        $this->assertTrue(Totp::verify($this->secret, '081 804', 1111111109));
    }

    public function testGenerateSecretIs20BytesBase32(): void
    {
        $s = Totp::generateSecret();
        $this->assertSame(32, strlen($s));
        $this->assertSame(20, strlen(Base32::decode($s)));
    }

    public function testUri(): void
    {
        $this->assertSame(
            'otpauth://totp/Dra%20Rubia:a%40b.test?secret=ABC&issuer=Dra%20Rubia',
            Totp::uri('ABC', 'a@b.test')
        );
    }
}
