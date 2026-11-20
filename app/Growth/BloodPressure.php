<?php
declare(strict_types=1);

namespace App\Growth;

/**
 * Classificacao da pressao arterial.
 * Adulto: Diretriz Brasileira de Hipertensao Arterial 2020.
 * Crianca/adolescente: AAP 2017 (Flynn JT et al., Pediatrics 2017;140(3):e20171904).
 * O percentil de altura usado na tabela vem da OMS (hfa), por decisao do plano.
 */
final class BloodPressure
{
    /** Percentis de altura das Tabelas 4 e 5 da AAP 2017. */
    public const HEIGHT_PCTS = [5, 10, 25, 50, 75, 90, 95];

    public const NOT_APPLICABLE = 'Não se aplica';
    public const NEEDS_HEIGHT = 'Altura necessária para classificar';

    private const CHILD_LEVELS = ['Normal', 'PA elevada', 'HA estágio 1', 'HA estágio 2'];
    private const ADULT_LEVELS = ['PA ótima', 'PA normal', 'Pré-hipertensão', 'HA estágio 1', 'HA estágio 2', 'HA estágio 3'];

    /** Usa a pior categoria entre sistolica e diastolica. */
    public static function classifyAdult(int $sys, int $dia): string
    {
        $s = self::level($sys, [120, 130, 140, 160, 180]);
        $d = self::level($dia, [80, 85, 90, 100, 110]);
        return self::ADULT_LEVELS[max($s, $d)];
    }

    /**
     * @param string $sex 'M'|'F'
     * @param int $ageYears anos completos (Age::years)
     * @param ?float $heightPercentile percentil de altura para idade (OMS); null = sem altura
     */
    public static function classifyChild(
        string $sex,
        int $ageYears,
        ?float $heightPercentile,
        int $sys,
        int $dia,
        BpReferenceRepository $ref,
    ): string {
        if ($ageYears < 1) {
            return self::NOT_APPLICABLE;
        }
        if ($ageYears >= 18) {
            return self::classifyAdult($sys, $dia);
        }
        if ($ageYears >= 13) {
            $s = self::level($sys, [120, 130, 140]);
            $d = self::level($dia, [80, 80, 90]); // diastolica 80-89 ja e estagio 1
            return self::CHILD_LEVELS[max($s, $d)];
        }
        if ($heightPercentile === null) {
            return self::NEEDS_HEIGHT;
        }
        $t = $ref->thresholds($sex, $ageYears, self::nearestHeightPct($heightPercentile));
        // Cada limiar: o menor entre o percentil da tabela e o valor absoluto.
        $s = self::level($sys, [min($t['sys90'], 120), min($t['sys95'], 130), min($t['sys95'] + 12, 140)]);
        $d = self::level($dia, [min($t['dia90'], 80), min($t['dia95'], 80), min($t['dia95'] + 12, 90)]);
        return self::CHILD_LEVELS[max($s, $d)];
    }

    /** Percentil de altura da tabela mais proximo; empate fica com o menor. */
    public static function nearestHeightPct(float $percentile): int
    {
        $best = self::HEIGHT_PCTS[0];
        foreach (self::HEIGHT_PCTS as $p) {
            if (abs($percentile - $p) < abs($percentile - $best)) {
                $best = $p;
            }
        }
        return $best;
    }

    /** Quantos limiares (crescentes) o valor atinge ou ultrapassa. */
    private static function level(int $value, array $cutoffs): int
    {
        $n = 0;
        foreach ($cutoffs as $c) {
            if ($value >= $c) {
                $n++;
            }
        }
        return $n;
    }
}
