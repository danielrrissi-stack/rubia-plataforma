<?php
declare(strict_types=1);

namespace App\Diets;

/** Gera database/seeds/food_4_diet_library.sql (upsert por nome) a partir do DietBuilder. */
final class DietSeed
{
    public static function sql(array $diets): string
    {
        $sql = "-- Dietas prontas (2 versoes x 52 semanas x niveis de 100 kcal), geradas por tools/build_diets.php\n"
            . "-- a partir de data/dietas/cardapio.php + TACO/POF. " . count($diets) . " dietas. Idempotente (upsert por code).\n";
        foreach (array_chunk($diets, 40) as $batch) {
            $values = array_map(static fn(array $d): string => sprintf(
                '(%s,%s,%d,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s)',
                self::q($d['code']), self::q($d['band']), $d['week'], self::q($d['season']), $d['kcal_target'],
                self::f($d['totals']['kcal']), self::f($d['totals']['protein']), self::f($d['totals']['carb']),
                self::f($d['totals']['fat']), self::f($d['totals']['fiber']),
                self::q($d['title']), self::q($d['guidance']),
                self::q(json_encode($d['structure'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            ), $batch);
            $sql .= "INSERT INTO diet_library (code,band,week,season,kcal_target,kcal,protein_g,carb_g,fat_g,fiber_g,title,guidance_text,structure_json) VALUES\n"
                . implode(",\n", $values) . "\n"
                . 'ON DUPLICATE KEY UPDATE band=VALUES(band), week=VALUES(week), season=VALUES(season), kcal_target=VALUES(kcal_target), '
                . 'kcal=VALUES(kcal), protein_g=VALUES(protein_g), carb_g=VALUES(carb_g), fat_g=VALUES(fat_g), fiber_g=VALUES(fiber_g), '
                . "title=VALUES(title), guidance_text=VALUES(guidance_text), structure_json=VALUES(structure_json);\n";
        }
        return $sql;
    }

    private static function f(float $v): string
    {
        return number_format($v, 1, '.', '');
    }

    private static function q(string $s): string
    {
        // Sem quebra de linha crua: o SeedLoader separa comandos por ";" no fim da linha.
        return "'" . str_replace(['\\', "'", "\r", "\n"], ['\\\\', "''", '', '\\n'], $s) . "'";
    }
}
