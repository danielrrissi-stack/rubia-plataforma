<?php
declare(strict_types=1);

namespace App\Nutrition;

/** Le os CSV da TACO (ver data/taco/SOURCES.md) e gera o seed database/seeds/food_1_taco.sql. */
final class TacoImporter
{
    /** @return list<array{taco_code:int,name:string,group_name:string,kcal:?float,protein_g:?float,carb_g:?float,fat_g:?float,fiber_g:?float}> */
    /**
     * $overridesCsv (opcional, data/taco/overrides.csv): alimentos que a TACO deixou sem valor (ex.: leite fluido 457/458)
     * recebem os cinco valores de outra fonte brasileira, citada na coluna `fonte`. Substitui os cinco valores do alimento.
     */
    public static function parse(string $foodCsv, string $nutrientsCsv, string $categoriesCsv, ?string $overridesCsv = null): array
    {
        $cats = [];
        foreach (self::csv($categoriesCsv) as $r) {
            $cats[(int) $r['id']] = $r['name'];
        }
        $nut = [];
        foreach (self::csv($nutrientsCsv) as $r) {
            $nut[(int) $r['foodId']] = $r;
        }
        foreach ($overridesCsv === null ? [] : self::csv($overridesCsv) as $o) {
            $code = (int) $o['taco_code'];
            if (!isset($nut[$code])) {
                throw new \RuntimeException('Override para alimento inexistente: ' . $code);
            }
            foreach (['kcal', 'protein', 'carbohydrates', 'lipids', 'dietaryFiber'] as $k) {
                $nut[$code][$k] = $o[$k];
            }
        }
        $out = [];
        foreach (self::csv($foodCsv) as $r) {
            $id = (int) $r['id'];
            $n = $nut[$id] ?? throw new \RuntimeException('Sem nutrientes para o alimento ' . $id);
            $out[] = [
                'taco_code' => $id,
                'name' => $r['name'],
                'group_name' => $cats[(int) $r['categoryId']] ?? throw new \RuntimeException('Categoria desconhecida no alimento ' . $id),
                'kcal' => self::num($n['kcal']),
                'protein_g' => self::num($n['protein']),
                'carb_g' => self::num($n['carbohydrates']),
                'fat_g' => self::num($n['lipids']),
                'fiber_g' => self::num($n['dietaryFiber']),
            ];
        }
        return $out;
    }

    public static function seedSql(array $rows): string
    {
        $sql = "-- Tabela Brasileira de Composicao de Alimentos (TACO), 4a ed. revisada e ampliada, NEPA/UNICAMP, 2011.\n"
            . "-- Gerado por tools/import_taco.php a partir de data/taco/*.csv (ver data/taco/SOURCES.md).\n"
            . '-- ' . count($rows) . " alimentos, por 100 g. Idempotente (upsert; nao altera a coluna de ativacao).\n";
        foreach (array_chunk($rows, 100) as $batch) {
            $values = array_map(static fn(array $r): string => sprintf(
                "(%d,'taco',%d,%s,%s,%s,%s,%s,%s,%s,%s)",
                $r['taco_code'],
                $r['taco_code'],
                self::q($r['name']),
                self::q(FoodRepository::normalize($r['name'])),
                self::q($r['group_name']),
                self::f($r['kcal']),
                self::f($r['protein_g']),
                self::f($r['carb_g']),
                self::f($r['fat_g']),
                self::f($r['fiber_g']),
            ), $batch);
            $sql .= "INSERT INTO foods (id,source,taco_code,name,name_search,group_name,kcal,protein_g,carb_g,fat_g,fiber_g) VALUES\n"
                . implode(",\n", $values) . "\n"
                . "ON DUPLICATE KEY UPDATE name=VALUES(name), name_search=VALUES(name_search), group_name=VALUES(group_name), "
                . "kcal=VALUES(kcal), protein_g=VALUES(protein_g), carb_g=VALUES(carb_g), fat_g=VALUES(fat_g), fiber_g=VALUES(fiber_g);\n";
        }
        return $sql;
    }

    /** @return list<array<string,string>> */
    private static function csv(string $path): array
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('Arquivo ausente: ' . $path);
        }
        $header = null;
        $rows = [];
        while (($f = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($f === [null] || $f === []) {
                continue;
            }
            $f = array_map(static fn($v): string => trim((string) $v), $f);
            if ($header === null) {
                $header = $f;
                continue;
            }
            $f = array_slice(array_pad($f, count($header), ''), 0, count($header));
            $rows[] = array_combine($header, $f);
        }
        fclose($h);
        return $rows;
    }

    /** Vazio, NA e * = nao disponivel (null); Tr (traco) = 0. */
    private static function num(string $v): ?float
    {
        if ($v === '' || $v === 'NA' || $v === '*') {
            return null;
        }
        if ($v === 'Tr') {
            return 0.0;
        }
        if (!is_numeric($v)) {
            throw new \RuntimeException('Valor nao numerico: ' . $v);
        }
        return (float) $v;
    }

    private static function f(?float $v): string
    {
        return $v === null ? 'NULL' : number_format($v, 1, '.', '');
    }

    private static function q(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
    }
}
