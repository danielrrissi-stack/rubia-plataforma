<?php
declare(strict_types=1);

namespace App\Labs;

final class LabClassifier
{
    public const MARK = [
        'alterado_alto' => '▲',
        'alterado_baixo' => '▼',
        'limitrofe_alto' => '◆',
        'limitrofe_baixo' => '◆',
        'normal' => '',
    ];

    public const LABEL = [
        'alterado_alto' => 'alterado (alto)',
        'alterado_baixo' => 'alterado (baixo)',
        'limitrofe_alto' => 'limítrofe',
        'limitrofe_baixo' => 'limítrofe',
        'normal' => 'normal',
    ];

    private const PHRASE = [
        'normal' => 'dentro do esperado',
        'limitrofe_alto' => 'um pouco acima do esperado',
        'alterado_alto' => 'acima do esperado',
        'limitrofe_baixo' => 'um pouco abaixo do esperado',
        'alterado_baixo' => 'abaixo do esperado',
    ];

    /** @param list<array> $ranges */
    public static function pickRange(array $ranges, string $sex, int $ageMonths): ?array
    {
        foreach ($ranges as $r) {
            if ($r['sex'] !== null && $r['sex'] !== $sex) {
                continue;
            }
            $max = $r['age_max_months'] ?? PHP_INT_MAX;
            if ($r['age_min_months'] <= $ageMonths && $ageMonths <= $max) {
                return $r;
            }
        }
        return null;
    }

    public static function classify(float $value, array $range): string
    {
        $ah = $range['abnormal_high'] ?? null;
        $bh = $range['borderline_high'] ?? null;
        $al = $range['abnormal_low'] ?? null;
        $bl = $range['borderline_low'] ?? null;
        if ($ah !== null && $value >= $ah) {
            return 'alterado_alto';
        }
        if ($bh !== null && $value >= $bh) {
            return 'limitrofe_alto';
        }
        if ($al !== null && $value <= $al) {
            return 'alterado_baixo';
        }
        if ($bl !== null && $value <= $bl) {
            return 'limitrofe_baixo';
        }
        return 'normal';
    }

    /** Meses completos de calendário (10º aniversário = 120). */
    public static function ageMonths(string $birthYmd, string $onYmd): int
    {
        $utc = new \DateTimeZone('UTC');
        $diff = (new \DateTimeImmutable($birthYmd, $utc))->diff(new \DateTimeImmutable($onYmd, $utc));
        return $diff->y * 12 + $diff->m;
    }

    public static function homaIr(?float $glicemiaMgDl, ?float $insulinaUuMl): ?float
    {
        if ($glicemiaMgDl === null || $insulinaUuMl === null || $glicemiaMgDl <= 0 || $insulinaUuMl <= 0) {
            return null;
        }
        return round($glicemiaMgDl * $insulinaUuMl / 405, 2);
    }

    public static function familyPhrase(?string $class): ?string
    {
        return $class === null ? null : (self::PHRASE[$class] ?? null);
    }
}
