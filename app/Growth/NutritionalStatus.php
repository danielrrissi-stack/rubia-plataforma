<?php
declare(strict_types=1);

namespace App\Growth;

/** Classificacao do estado nutricional pelos pontos de corte OMS (escore Z). */
final class NutritionalStatus
{
    /** Ultimo dia (inclusive) em que valem os cortes de 0-5 anos (OMS 2006, < 60 meses). */
    public const UNDER5_MAX_DAYS = 1826;

    /** @param string $indicator 'bfa'|'wflh'|'hfa'|'wfa'|'hcfa' */
    public static function classify(string $indicator, float $z, int $ageDays): string
    {
        return match ($indicator) {
            'bfa', 'wflh' => $ageDays <= self::UNDER5_MAX_DAYS ? self::weightUnder5($z) : self::bmiSchool($z),
            'hfa' => match (true) {
                $z < -3 => 'Muito baixa estatura para a idade',
                $z < -2 => 'Baixa estatura para a idade',
                default => 'Estatura adequada para a idade',
            },
            'wfa' => match (true) {
                $z < -3 => 'Muito baixo peso para a idade',
                $z < -2 => 'Baixo peso para a idade',
                $z <= 2 => 'Peso adequado para a idade',
                default => 'Peso elevado para a idade',
            },
            'hcfa' => match (true) {
                $z < -2 => 'Abaixo do esperado',
                $z <= 2 => 'Adequado',
                default => 'Acima do esperado',
            },
            default => throw new \InvalidArgumentException('Indicador desconhecido: ' . $indicator),
        };
    }

    private static function weightUnder5(float $z): string
    {
        return match (true) {
            $z < -3 => 'Magreza acentuada',
            $z < -2 => 'Magreza',
            $z <= 1 => 'Eutrofia',
            $z <= 2 => 'Risco de sobrepeso',
            $z <= 3 => 'Sobrepeso',
            default => 'Obesidade',
        };
    }

    private static function bmiSchool(float $z): string
    {
        return match (true) {
            $z < -3 => 'Magreza acentuada',
            $z < -2 => 'Magreza',
            $z <= 1 => 'Eutrofia',
            $z <= 2 => 'Sobrepeso',
            $z <= 3 => 'Obesidade',
            default => 'Obesidade grave',
        };
    }
}
