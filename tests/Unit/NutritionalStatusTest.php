<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Growth\NutritionalStatus;
use PHPUnit\Framework\TestCase;

final class NutritionalStatusTest extends TestCase
{
    private const UNDER5 = 1000;
    private const SCHOOL = 3000;

    public function testBfaSchoolAgeBoundaries(): void
    {
        $c = fn(float $z) => NutritionalStatus::classify('bfa', $z, self::SCHOOL);
        $this->assertSame('Magreza acentuada', $c(-3.01));
        $this->assertSame('Magreza', $c(-3.0));
        $this->assertSame('Eutrofia', $c(-2.0));
        $this->assertSame('Eutrofia', $c(1.0));
        $this->assertSame('Sobrepeso', $c(1.0001));
        $this->assertSame('Sobrepeso', $c(2.0));
        $this->assertSame('Obesidade', $c(3.0));
        $this->assertSame('Obesidade grave', $c(3.01));
    }

    public function testBfaUnderFive(): void
    {
        $this->assertSame('Obesidade', NutritionalStatus::classify('bfa', 3.01, self::UNDER5));
        $this->assertSame('Sobrepeso', NutritionalStatus::classify('bfa', 3.0, self::UNDER5));
        $this->assertSame('Risco de sobrepeso', NutritionalStatus::classify('bfa', 1.5, self::UNDER5));
        $this->assertSame('Eutrofia', NutritionalStatus::classify('wflh', 1.0, self::UNDER5));
        $this->assertSame('Magreza', NutritionalStatus::classify('wflh', -2.5, self::UNDER5));
    }

    public function testUnderFiveBandEndsAt1826Days(): void
    {
        $this->assertSame('Obesidade', NutritionalStatus::classify('bfa', 3.5, 1826));
        $this->assertSame('Obesidade grave', NutritionalStatus::classify('bfa', 3.5, 1827));
    }

    public function testHfa(): void
    {
        $this->assertSame('Estatura adequada para a idade', NutritionalStatus::classify('hfa', -2.0, self::SCHOOL));
        $this->assertSame('Baixa estatura para a idade', NutritionalStatus::classify('hfa', -2.01, self::SCHOOL));
        $this->assertSame('Muito baixa estatura para a idade', NutritionalStatus::classify('hfa', -3.01, self::SCHOOL));
    }

    public function testWfaAndHcfa(): void
    {
        $this->assertSame('Muito baixo peso para a idade', NutritionalStatus::classify('wfa', -3.5, self::UNDER5));
        $this->assertSame('Baixo peso para a idade', NutritionalStatus::classify('wfa', -3.0, self::UNDER5));
        $this->assertSame('Peso adequado para a idade', NutritionalStatus::classify('wfa', 2.0, self::UNDER5));
        $this->assertSame('Peso elevado para a idade', NutritionalStatus::classify('wfa', 2.01, self::UNDER5));
        $this->assertSame('Abaixo do esperado', NutritionalStatus::classify('hcfa', -2.01, self::UNDER5));
        $this->assertSame('Adequado', NutritionalStatus::classify('hcfa', 2.0, self::UNDER5));
        $this->assertSame('Acima do esperado', NutritionalStatus::classify('hcfa', 2.01, self::UNDER5));
    }

    public function testUnknownIndicatorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NutritionalStatus::classify('xyz', 0.0, self::UNDER5);
    }
}
