<?php
declare(strict_types=1);

namespace App\Growth;

use App\Core\Database;

/**
 * Le as tabelas LMS oficiais da OMS (data/who/*.txt, separadas por TAB) e gera
 * comandos REPLACE em lote para a tabela who_lms. Parsing estrito: qualquer linha
 * fora do formato esperado aborta com excecao.
 */
final class WhoLmsImporter
{
    /** arquivo => [indicador, fonte, coluna x esperada no cabecalho] */
    public const FILES = [
        'weianthro.txt'  => ['wfa', 'who2006', 'age'],
        'lenanthro.txt'  => ['hfa', 'who2006', 'age'],
        'bmianthro.txt'  => ['bfa', 'who2006', 'age'],
        'hcanthro.txt'   => ['hcfa', 'who2006', 'age'],
        'wflanthro.txt'  => ['wfl', 'who2006', 'length'],
        'wfhanthro.txt'  => ['wfh', 'who2006', 'height'],
        'bfawho2007.txt' => ['bfa', 'who2007', 'age'],
        'hfawho2007.txt' => ['hfa', 'who2007', 'age'],
        'wfawho2007.txt' => ['wfa', 'who2007', 'age'],
    ];

    private const NUM = '/^-?\d+(\.\d+)?$/';

    /** @return list<array{indicator:string,sex:int,x:float,source:string,l:float,m:float,s:float}> */
    public static function parseDir(string $dir): array
    {
        $rows = [];
        foreach (self::FILES as $file => [$indicator, $source, $xCol]) {
            $path = rtrim($dir, '/\\') . '/' . $file;
            if (!is_file($path)) {
                throw new \RuntimeException('Arquivo OMS ausente: ' . $path);
            }
            foreach (self::parseFile($path, $indicator, $source, $xCol) as $r) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /** @return list<array{indicator:string,sex:int,x:float,source:string,l:float,m:float,s:float}> */
    public static function parseFile(string $path, string $indicator, string $source, string $xCol): array
    {
        $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path));
        $header = explode("\t", trim((string) array_shift($lines)));
        if (array_slice($header, 0, 5) !== ['sex', $xCol, 'l', 'm', 's'] || count($header) > 6) {
            throw new \RuntimeException("Cabecalho inesperado em $path: " . implode(',', $header));
        }
        $width = count($header);
        $out = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $f = explode("\t", trim($line));
            $where = "$path linha " . ($i + 2);
            if (count($f) !== $width || !in_array($f[0], ['1', '2'], true)) {
                throw new \RuntimeException("Linha invalida em $where");
            }
            for ($k = 1; $k <= 4; $k++) {
                if (!preg_match(self::NUM, $f[$k])) {
                    throw new \RuntimeException("Numero invalido em $where");
                }
            }
            if ($width === 6 && !in_array($f[5], ['L', 'H'], true)) {
                throw new \RuntimeException("Coluna loh invalida em $where");
            }
            $x = (float) $f[1];
            $m = (float) $f[3];
            $s = (float) $f[4];
            if ($x < 0 || $x >= 100000 || $m <= 0 || $s <= 0) {
                throw new \RuntimeException("Valor fora da faixa em $where");
            }
            $out[] = [
                'indicator' => $indicator,
                'sex' => (int) $f[0],
                'x' => $x,
                'source' => $source,
                'l' => (float) $f[2],
                'm' => $m,
                's' => $s,
            ];
        }
        return $out;
    }

    /**
     * @param list<array{indicator:string,sex:int,x:float,source:string,l:float,m:float,s:float}> $rows
     * @return list<string>
     */
    public static function statements(array $rows, int $batch = 1000): array
    {
        $sql = [];
        foreach (array_chunk($rows, $batch) as $chunk) {
            $values = array_map(
                static fn(array $r): string => sprintf(
                    "('%s',%d,%s,'%s',%s,%s,%s)",
                    $r['indicator'],
                    $r['sex'],
                    self::num($r['x']),
                    $r['source'],
                    self::num($r['l']),
                    self::num($r['m']),
                    self::num($r['s']),
                ),
                $chunk,
            );
            $sql[] = "REPLACE INTO who_lms (indicator,sex,x,source,l,m,s) VALUES\n" . implode(",\n", $values) . ';';
        }
        return $sql;
    }

    /** @param list<string> $statements */
    public static function execute(Database $db, array $statements): void
    {
        $pdo = $db->pdo();
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /** Executa um arquivo gerado por seedSql() (comandos separados por ";\n"). */
    public static function loadSeed(Database $db, string $path): void
    {
        $chunks = explode(";\n", str_replace("\r\n", "\n", (string) file_get_contents($path)));
        $statements = [];
        foreach ($chunks as $c) {
            $c = trim(preg_replace('/^--.*$/m', '', $c));
            if ($c !== '') {
                $statements[] = $c;
            }
        }
        self::execute($db, $statements);
    }

    /** @param list<string> $statements */
    public static function seedSql(array $statements, int $rowCount): string
    {
        return "-- Tabelas LMS da OMS (2006: 0-5 anos; 2007: 5-19 anos) para who_lms.\n"
            . "-- Gerado por tools/import_who.php a partir de data/who/*.txt (ver data/who/SOURCES.md).\n"
            . "-- $rowCount linhas. Idempotente (REPLACE).\n"
            . implode("\n", $statements) . "\n";
    }

    private static function num(float $v): string
    {
        return (string) $v; // representacao mais curta que preserva o valor (serialize_precision -1)
    }
}
