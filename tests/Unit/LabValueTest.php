<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Labs\LabValue;
use PHPUnit\Framework\TestCase;

final class LabValueTest extends TestCase
{
    public function testParse(): void
    {
        $this->assertNull(LabValue::parse('  '));
        $this->assertSame(['num' => 5.7, 'text' => null], LabValue::parse('5,7'));
        $this->assertSame(['num' => 5.7, 'text' => null], LabValue::parse(' 5.7 '));
        $this->assertSame(['num' => 98.0, 'text' => null], LabValue::parse('98'));
        $this->assertSame(['num' => null, 'text' => '<5'], LabValue::parse('<5'));
        $this->assertSame(['num' => null, 'text' => 'indetectável'], LabValue::parse('indetectável'));
    }

    public function testInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LabValue::parse('9,8,1');
    }
}
