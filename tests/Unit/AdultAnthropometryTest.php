<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Growth\AdultAnthropometry as A;
use PHPUnit\Framework\TestCase;

final class AdultAnthropometryTest extends TestCase
{
    public function testBmi(): void
    {
        $this->assertSame(26.1, A::bmi(80, 175));
        $this->assertSame(22.9, A::bmi(70, 175)); // 22,857 -> 22,9
    }

    public function testBmiClassBoundaries(): void
    {
        $this->assertSame('Baixo peso', A::bmiClass(18.4));
        $this->assertSame('Eutrofia', A::bmiClass(18.5));
        $this->assertSame('Eutrofia', A::bmiClass(24.9));
        $this->assertSame('Sobrepeso', A::bmiClass(25.0));
        $this->assertSame('Sobrepeso', A::bmiClass(29.9));
        $this->assertSame('Obesidade grau I', A::bmiClass(30.0));
        $this->assertSame('Obesidade grau I', A::bmiClass(34.9));
        $this->assertSame('Obesidade grau II', A::bmiClass(35.0));
        $this->assertSame('Obesidade grau II', A::bmiClass(39.9));
        $this->assertSame('Obesidade grau III', A::bmiClass(40.0));
    }

    public function testBmiClassUsesValueRoundedToOneDecimal(): void
    {
        $this->assertSame('Sobrepeso', A::bmiClass(24.96)); // arredonda para 25,0
        $this->assertSame('Eutrofia', A::bmiClass(24.94));
    }

    public function testWaistRisk(): void
    {
        $this->assertSame('Sem risco aumentado', A::waistRisk('F', 79.9));
        $this->assertSame('Risco aumentado', A::waistRisk('F', 80));
        $this->assertSame('Risco aumentado', A::waistRisk('F', 87.9));
        $this->assertSame('Risco muito aumentado', A::waistRisk('F', 88));
        $this->assertSame('Sem risco aumentado', A::waistRisk('M', 93.9));
        $this->assertSame('Risco aumentado', A::waistRisk('M', 94));
        $this->assertSame('Risco aumentado', A::waistRisk('M', 101.9));
        $this->assertSame('Risco muito aumentado', A::waistRisk('M', 102));
    }

    public function testWhr(): void
    {
        $this->assertSame(0.89, A::whr(89, 100));
        $this->assertSame(0.86, A::whr(80, 93)); // 0,8602
    }

    public function testWhrRisk(): void
    {
        $this->assertSame('Risco aumentado', A::whrRisk('M', 0.91));
        $this->assertSame('Sem risco aumentado', A::whrRisk('M', 0.90));
        $this->assertSame('Sem risco aumentado', A::whrRisk('F', 0.85));
        $this->assertSame('Risco aumentado', A::whrRisk('F', 0.86));
    }

    public function testInvalidSex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        A::waistRisk('X', 90);
    }
}
