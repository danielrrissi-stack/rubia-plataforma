<?php
declare(strict_types=1);

namespace App\Growth;

/**
 * Escores Z / percentis OMS para 0-19 anos, seguindo os programas oficiais
 * (anthro e anthroplus): ate 1826 dias (< 60 meses) OMS 2006 por dia; depois
 * OMS 2007 por mes, valido enquanto meses < 229.
 */
final class GrowthCalculator
{
    public const WHO2006_MAX_DAYS = 1826;
    public const WHO2007_MAX_MONTHS = 229;   // exclusivo
    public const WFA2007_MAX_MONTHS = 121;   // exclusivo
    public const LENGTH_MAX_DAYS = 730;      // < 731 dias: comprimento (deitado)
    public const POSITION_DELTA = 0.7;

    /** Limites de plausibilidade (flags OMS): [min, max]. */
    private const FLAGS = [
        'hfa' => [-6, 6],
        'wfa' => [-6, 5],
        'bfa' => [-5, 5],
        'wflh' => [-5, 5],
        'hcfa' => [-5, 5],
    ];

    public function __construct(private WhoReferenceRepository $ref)
    {
    }

    /**
     * @param string $sex 'M'|'F'
     * @param ?string $position 'deitado'|'em_pe'|null
     * @return array{bfa:?array,wfa:?array,hfa:?array,wflh:?array,hcfa:?array}
     */
    public function indicators(
        string $sex,
        string $birth,
        string $measuredOn,
        ?float $weightKg,
        ?float $heightCm,
        ?string $position,
        ?float $headCm,
    ): array {
        $out = ['bfa' => null, 'wfa' => null, 'hfa' => null, 'wflh' => null, 'hcfa' => null];
        $days = Age::days($birth, $measuredOn);
        $months = Age::months($birth, $measuredOn);
        if ($days < 0) {
            return $out;
        }
        if ($days <= self::WHO2006_MAX_DAYS) {
            $source = 'who2006';
            $xAge = (float) $days;
        } elseif ($months < self::WHO2007_MAX_MONTHS) {
            $source = 'who2007';
            $xAge = $months;
        } else {
            return $out;
        }

        $height = $heightCm === null ? null : $this->correctHeight($heightCm, $days, $position);

        if ($weightKg !== null && $height !== null && $height > 0) {
            $bmi = $weightKg / (($height / 100) ** 2);
            $out['bfa'] = $this->score('bfa', 'bfa', $sex, $xAge, $source, $bmi, true, $days);
        }
        if ($weightKg !== null && ($source === 'who2006' || $months < self::WFA2007_MAX_MONTHS)) {
            $out['wfa'] = $this->score('wfa', 'wfa', $sex, $xAge, $source, $weightKg, true, $days);
        }
        if ($height !== null) {
            $out['hfa'] = $this->score('hfa', 'hfa', $sex, $xAge, $source, $height, false, $days);
        }
        if ($source === 'who2006' && $weightKg !== null && $height !== null) {
            [$table, $min, $max] = $days <= self::LENGTH_MAX_DAYS ? ['wfl', 45.0, 110.0] : ['wfh', 65.0, 120.0];
            if ($height >= $min && $height <= $max) {
                $out['wflh'] = $this->score('wflh', $table, $sex, $height, $source, $weightKg, true, $days);
            }
        }
        if ($source === 'who2006' && $headCm !== null) {
            $out['hcfa'] = $this->score('hcfa', 'hcfa', $sex, $xAge, $source, $headCm, false, $days);
        }
        return $out;
    }

    /** < 731 dias medido em pe: +0,7 cm; >= 731 dias medido deitado: -0,7 cm. */
    public function correctHeight(float $cm, int $days, ?string $position): float
    {
        if ($days <= self::LENGTH_MAX_DAYS && $position === 'em_pe') {
            return $cm + self::POSITION_DELTA;
        }
        if ($days > self::LENGTH_MAX_DAYS && $position === 'deitado') {
            return $cm - self::POSITION_DELTA;
        }
        return $cm;
    }

    /** @return array{z:float,percentile:float,value:float,status:string,implausible:bool,source:string}|null */
    private function score(
        string $indicator,
        string $table,
        string $sex,
        float $x,
        string $source,
        float $value,
        bool $restricted,
        int $days,
    ): ?array {
        $lms = $this->ref->lms($table, $sex, $x, $source);
        if ($lms === null || $value <= 0) {
            return null;
        }
        $raw = $restricted
            ? Lms::zRestricted($value, $lms['l'], $lms['m'], $lms['s'])
            : Lms::z($value, $lms['l'], $lms['m'], $lms['s']);
        $z = round($raw, 2) + 0.0; // OMS (anthro/anthroplus) arredonda o escore Z em 2 casas
        [$lo, $hi] = self::FLAGS[$indicator];
        return [
            'z' => $z,
            'percentile' => Lms::percentile($raw),
            'value' => $value,
            'status' => NutritionalStatus::classify($indicator, $z, $days),
            'implausible' => $z < $lo || $z > $hi,
            'source' => $source,
        ];
    }
}
