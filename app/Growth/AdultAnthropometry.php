<?php
declare(strict_types=1);

namespace App\Growth;

/** Classificacoes antropometricas do adulto (OMS): IMC, circunferencia da cintura e RCQ. */
final class AdultAnthropometry
{
    /** IMC (kg/m2) com 1 casa decimal. */
    public static function bmi(float $kg, float $cm): float
    {
        if ($kg <= 0 || $cm <= 0) {
            throw new \InvalidArgumentException('Peso e altura devem ser positivos.');
        }
        $m = $cm / 100;
        return round($kg / ($m * $m), 1);
    }

    /** Compara com o valor arredondado a 1 casa (24,96 -> 25,0 -> Sobrepeso). */
    public static function bmiClass(float $bmi): string
    {
        $b = round($bmi, 1);
        return match (true) {
            $b < 18.5 => 'Baixo peso',
            $b < 25.0 => 'Eutrofia',
            $b < 30.0 => 'Sobrepeso',
            $b < 35.0 => 'Obesidade grau I',
            $b < 40.0 => 'Obesidade grau II',
            default => 'Obesidade grau III',
        };
    }

    /** Risco metabolico pela circunferencia da cintura (M: 94/102 cm; F: 80/88 cm). */
    public static function waistRisk(string $sex, float $cm): string
    {
        [$high, $veryHigh] = self::bySex($sex, [94.0, 102.0], [80.0, 88.0]);
        return match (true) {
            $cm >= $veryHigh => 'Risco muito aumentado',
            $cm >= $high => 'Risco aumentado',
            default => 'Sem risco aumentado',
        };
    }

    /** Relacao cintura/quadril com 2 casas decimais. */
    public static function whr(float $waist, float $hip): float
    {
        if ($waist <= 0 || $hip <= 0) {
            throw new \InvalidArgumentException('Cintura e quadril devem ser positivos.');
        }
        return round($waist / $hip, 2);
    }

    /** Risco se RCQ > 0,90 (M) ou > 0,85 (F). */
    public static function whrRisk(string $sex, float $whr): string
    {
        $limit = self::bySex($sex, 0.90, 0.85);
        return round($whr, 2) > $limit ? 'Risco aumentado' : 'Sem risco aumentado';
    }

    private static function bySex(string $sex, mixed $male, mixed $female): mixed
    {
        return match ($sex) {
            'M' => $male,
            'F' => $female,
            default => throw new \InvalidArgumentException('Sexo invalido: ' . $sex),
        };
    }
}
