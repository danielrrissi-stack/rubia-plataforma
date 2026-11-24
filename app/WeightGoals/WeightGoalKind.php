<?php
declare(strict_types=1);

namespace App\WeightGoals;

use App\Growth\Age;

/**
 * Tipo de meta de peso permitido pela idade na data de criacao:
 * < 13 anos so escore-z do IMC; 13 anos ate o fim das curvas da OMS (229 meses) escolhe; adulto so kg.
 */
final class WeightGoalKind
{
    public const PESO = 'peso';
    public const IMC_Z = 'imc_z';
    public const ADULT_MONTHS = 229;
    public const TEEN_MIN_MONTHS = 156;
    public const LABELS = [self::PESO => 'Peso (kg)', self::IMC_Z => 'Escore-z do IMC'];

    /** @return list<string> */
    public static function allowed(string $birth, string $onYmd): array
    {
        $months = Age::months($birth, $onYmd);
        if ($months >= self::ADULT_MONTHS) {
            return [self::PESO];
        }
        return $months >= self::TEEN_MIN_MONTHS ? [self::IMC_Z, self::PESO] : [self::IMC_Z];
    }

    /** Ultimo dia em que o paciente ainda tem menos de 229 meses (limite do prazo da meta em escore-z). */
    public static function zDeadlineLimit(string $birth): string
    {
        $b = new \DateTimeImmutable(substr($birth, 0, 10), new \DateTimeZone('UTC'));
        $d = $b->modify('+' . (int) floor(self::ADULT_MONTHS * Age::DAYS_PER_MONTH) . ' days');
        while (Age::months($birth, $d->format('Y-m-d')) >= self::ADULT_MONTHS) {
            $d = $d->modify('-1 day');
        }
        while (Age::months($birth, $d->modify('+1 day')->format('Y-m-d')) < self::ADULT_MONTHS) {
            $d = $d->modify('+1 day');
        }
        return $d->format('Y-m-d');
    }
}
