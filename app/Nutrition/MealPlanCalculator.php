<?php
declare(strict_types=1);

namespace App\Nutrition;

/** Calculo nutricional do plano estruturado (sem banco, sem arredondar). */
final class MealPlanCalculator
{
    private const KEYS = ['kcal', 'protein', 'carb', 'fat', 'fiber'];

    /**
     * Item resolvido: `grams` = total em gramas do item; `per100` = valores por 100 g (nulos contam zero e marcam `missing`).
     * @return array{kcal:float,protein:float,carb:float,fat:float,fiber:float,missing:bool}
     */
    public static function item(array $item): array
    {
        $grams = (float) ($item['grams'] ?? 0);
        $per100 = (array) ($item['per100'] ?? []);
        $out = [];
        $missing = false;
        foreach (self::KEYS as $k) {
            $v = $per100[$k] ?? null;
            if ($v === null) {
                $missing = true;
                $out[$k] = 0.0;
            } else {
                $out[$k] = (float) $v * $grams / 100.0;
            }
        }
        $out['missing'] = $missing;
        return $out;
    }

    /**
     * Soma dos itens da opcao; substitutos nao entram.
     * @return array{kcal:float,protein:float,carb:float,fat:float,fiber:float}
     */
    public static function option(array $option): array
    {
        $sum = array_fill_keys(self::KEYS, 0.0);
        foreach ((array) ($option['items'] ?? []) as $item) {
            $r = self::item((array) $item);
            foreach (self::KEYS as $k) {
                $sum[$k] += $r[$k];
            }
        }
        return $sum;
    }

    /**
     * Total do dia = soma da Opcao 1 de cada refeicao.
     * @return array{kcal:float,protein:float,carb:float,fat:float,fiber:float}
     */
    public static function day(array $structure): array
    {
        $sum = array_fill_keys(self::KEYS, 0.0);
        foreach ((array) ($structure['meals'] ?? []) as $meal) {
            $opts = (array) (((array) $meal)['options'] ?? []);
            if ($opts === []) {
                continue;
            }
            $r = self::option((array) reset($opts));
            foreach (self::KEYS as $k) {
                $sum[$k] += $r[$k];
            }
        }
        return $sum;
    }

    /**
     * % das calorias de proteina/carboidrato/gordura (base = prot*4 + carb*4 + gord*9), 1 casa.
     * @return array{protein:float,carb:float,fat:float}
     */
    public static function macroPercents(array $totals): array
    {
        $p = (float) ($totals['protein'] ?? 0) * 4;
        $c = (float) ($totals['carb'] ?? 0) * 4;
        $f = (float) ($totals['fat'] ?? 0) * 9;
        $base = $p + $c + $f;
        if ($base <= 0) {
            return ['protein' => 0.0, 'carb' => 0.0, 'fat' => 0.0];
        }
        return [
            'protein' => round($p / $base * 100, 1),
            'carb' => round($c / $base * 100, 1),
            'fat' => round($f / $base * 100, 1),
        ];
    }
}
