<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Num;
use PHPUnit\Framework\TestCase;

final class NumTest extends TestCase
{
    public function testParsesCommaAndDot(): void
    {
        $this->assertSame(32.5, Num::parse('32,5'));
        $this->assertSame(32.5, Num::parse('32.5'));
        $this->assertSame(1234.5, Num::parse('1.234,5'));
        $this->assertSame(1234.5, Num::parse(' 1.234,5 '));
        $this->assertSame(32.0, Num::parse('32'));
    }

    public function testEmptyIsNull(): void
    {
        $this->assertNull(Num::parse(''));
        $this->assertNull(Num::parse(null));
        $this->assertNull(Num::parse('  '));
    }

    public function testInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Num::parse('abc');
    }

    public function testMultipleCommasThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Num::parse('12,3,4');
    }
}
