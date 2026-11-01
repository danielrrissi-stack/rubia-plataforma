<?php
declare(strict_types=1);

namespace App\Growth;

/**
 * Tabelas 4 (meninos) e 5 (meninas) da AAP 2017 -> data/bp/aap2017.csv -> seed SQL de bp_reference.
 * Origem dos arquivos brutos: data/bp/SOURCES.md. Parsing estrito: qualquer desvio aborta.
 */
final class BpReferenceImporter
{
    public const CSV_HEADER = 'sex,age,height_pct,bp_pct,systolic,diastolic';
    public const BP_PCTS = [50, 90, 95];

    private const DAT_HEADER = 'age bp_% 5 10 25 50 75 90 95 5 10 25 50 75 90 95';

    /**
     * Le um .dat do pacote pedbp (separado por espaco: idade, percentil de PA,
     * 7 sistolicas e 7 diastolicas nas colunas de percentil de altura 5..95).
     * @return list<array{sex:int,age:int,height_pct:int,bp_pct:int,systolic:int,diastolic:int}>
     */
    public static function parseDat(string $path, int $sex): array
    {
        $lines = self::lines($path);
        if (preg_replace('/\s+/', ' ', trim((string) array_shift($lines))) !== self::DAT_HEADER) {
            throw new \RuntimeException("Cabecalho inesperado em $path");
        }
        $out = [];
        foreach ($lines as $i => $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) !== 16) {
                throw new \RuntimeException("Linha invalida em $path linha " . ($i + 2));
            }
            foreach (BloodPressure::HEIGHT_PCTS as $k => $h) {
                $out[] = self::row([$sex, $f[0], $h, $f[1], $f[2 + $k], $f[9 + $k]], "$path linha " . ($i + 2));
            }
        }
        self::assertComplete($out, $path, [$sex]);
        return $out;
    }

    /** @return list<array{sex:int,age:int,height_pct:int,bp_pct:int,systolic:int,diastolic:int}> */
    public static function parseCsv(string $path): array
    {
        $lines = self::lines($path);
        if (trim((string) array_shift($lines)) !== self::CSV_HEADER) {
            throw new \RuntimeException("Cabecalho inesperado em $path");
        }
        $out = [];
        foreach ($lines as $i => $line) {
            $f = explode(',', trim($line));
            if (count($f) !== 6) {
                throw new \RuntimeException("Linha invalida em $path linha " . ($i + 2));
            }
            $out[] = self::row($f, "$path linha " . ($i + 2));
        }
        self::assertComplete($out, $path, [1, 2]);
        return $out;
    }

    /** @param list<array{sex:int,age:int,height_pct:int,bp_pct:int,systolic:int,diastolic:int}> $rows */
    public static function toCsv(array $rows): string
    {
        usort($rows, static fn(array $a, array $b): int => [$a['sex'], $a['age'], $a['height_pct'], $a['bp_pct']]
            <=> [$b['sex'], $b['age'], $b['height_pct'], $b['bp_pct']]);
        $csv = self::CSV_HEADER . "\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [$r['sex'], $r['age'], $r['height_pct'], $r['bp_pct'], $r['systolic'], $r['diastolic']]) . "\n";
        }
        return $csv;
    }

    /** @param list<array{sex:int,age:int,height_pct:int,bp_pct:int,systolic:int,diastolic:int}> $rows */
    public static function seedSql(array $rows): string
    {
        $values = array_map(
            static fn(array $r): string => sprintf('(%d,%d,%d,%d,%d,%d)', $r['sex'], $r['age'], $r['height_pct'], $r['bp_pct'], $r['systolic'], $r['diastolic']),
            $rows,
        );
        return "-- Limiares de PA da AAP 2017 (Flynn JT et al., Pediatrics 2017;140(3):e20171904, Tabelas 4 e 5).\n"
            . "-- Gerado por tools/import_bp.php a partir de data/bp/aap2017.csv (ver data/bp/SOURCES.md).\n"
            . '-- ' . count($rows) . " linhas. Idempotente (REPLACE).\n"
            . "REPLACE INTO bp_reference (sex,age_years,height_pct,bp_pct,systolic,diastolic) VALUES\n"
            . implode(",\n", $values) . ";\n";
    }

    /** @return list<string> */
    private static function lines(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Arquivo ausente: ' . $path);
        }
        $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [];
        return array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));
    }

    /** @return array{sex:int,age:int,height_pct:int,bp_pct:int,systolic:int,diastolic:int} */
    private static function row(array $f, string $where): array
    {
        foreach ($f as $v) {
            if (!preg_match('/^\d{1,3}$/', (string) $v)) {
                throw new \RuntimeException("Numero invalido em $where");
            }
        }
        $r = array_combine(['sex', 'age', 'height_pct', 'bp_pct', 'systolic', 'diastolic'], array_map('intval', $f));
        if (!in_array($r['sex'], [1, 2], true) || $r['age'] < 1 || $r['age'] > 17
            || !in_array($r['height_pct'], BloodPressure::HEIGHT_PCTS, true) || !in_array($r['bp_pct'], self::BP_PCTS, true)
            || $r['systolic'] < 70 || $r['systolic'] > 160 || $r['diastolic'] < 30 || $r['diastolic'] > 110) {
            throw new \RuntimeException("Valor fora da faixa em $where");
        }
        return $r;
    }

    /** Exige exatamente uma linha por (sexo, idade 1-17, percentil de altura, percentil de PA). */
    private static function assertComplete(array $rows, string $path, array $sexes): void
    {
        $keys = [];
        foreach ($rows as $r) {
            $keys[$r['sex'] . '-' . $r['age'] . '-' . $r['height_pct'] . '-' . $r['bp_pct']] = true;
        }
        $expected = count($sexes) * 17 * count(BloodPressure::HEIGHT_PCTS) * count(self::BP_PCTS);
        if (count($rows) !== $expected || count($keys) !== $expected) {
            throw new \RuntimeException("Tabela incompleta ou duplicada em $path: " . count($rows) . " linhas, esperado $expected");
        }
    }
}
