<?php
declare(strict_types=1);

namespace App\Nutrition;

/** Le data/pof/medidas.csv (ver data/pof/SOURCES.md) e gera o seed database/seeds/food_2_pof_measures.sql. */
final class PofMeasureImporter
{
    /** @return list<array{taco_code:int,name:string,plural:?string,grams:float,page:int}> */
    public static function parseCsv(string $path): array
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('Arquivo ausente: ' . $path);
        }
        $rows = [];
        $line = 0;
        while (($f = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $line++;
            if ($f === [null] || $f === []) {
                continue;
            }
            if ($line === 1) {
                if (array_map('trim', $f) !== ['taco_code', 'medida', 'medida_plural', 'gramas', 'pagina_pof']) {
                    throw new \RuntimeException('Cabecalho inesperado em ' . $path);
                }
                continue;
            }
            $f = array_map(static fn($v): string => trim((string) $v), array_pad($f, 5, ''));
            [$code, $name, $plural, $grams, $page] = $f;
            if (!ctype_digit($code) || $name === '' || !is_numeric($grams) || (float) $grams <= 0 || !ctype_digit($page) || (int) $page < 1) {
                throw new \RuntimeException('Linha ' . $line . ' invalida em ' . basename($path));
            }
            $rows[] = ['taco_code' => (int) $code, 'name' => $name, 'plural' => $plural === '' ? null : $plural, 'grams' => (float) $grams, 'page' => (int) $page];
        }
        fclose($h);
        return $rows;
    }

    /** id = taco_code*100 + n (n sequencial por alimento, na ordem do CSV). */
    public static function seedSql(array $rows): string
    {
        $seq = [];
        $values = [];
        foreach ($rows as $r) {
            $n = $seq[$r['taco_code']] = ($seq[$r['taco_code']] ?? 0) + 1;
            if ($n > 99) {
                throw new \RuntimeException('Mais de 99 medidas para o alimento ' . $r['taco_code']);
            }
            $values[] = sprintf(
                "(%d,%d,'pof',%s,%s,%s)",
                $r['taco_code'] * 100 + $n,
                $r['taco_code'],
                self::q($r['name']),
                $r['plural'] === null ? 'NULL' : self::q($r['plural']),
                number_format($r['grams'], 1, '.', ''),
            );
        }
        $sql = "-- Medidas caseiras: IBGE, Pesquisa de Orcamentos Familiares 2008-2009, Tabela de Medidas Referidas para os Alimentos Consumidos no Brasil.\n"
            . "-- Gerado por tools/import_pof.php a partir de data/pof/medidas.csv (ver data/pof/SOURCES.md).\n"
            . '-- ' . count($rows) . " medidas, id = codigo TACO * 100 + n. Idempotente (upsert; nao altera a coluna de ativacao).\n";
        foreach (array_chunk($values, 100) as $batch) {
            $sql .= "INSERT INTO food_measures (id,food_id,source,name,name_plural,grams) VALUES\n"
                . implode(",\n", $batch) . "\n"
                . "ON DUPLICATE KEY UPDATE name=VALUES(name), name_plural=VALUES(name_plural), grams=VALUES(grams);\n";
        }
        return $sql;
    }

    private static function q(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
    }
}
