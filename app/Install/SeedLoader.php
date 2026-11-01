<?php
declare(strict_types=1);

namespace App\Install;

use App\Core\Database;

/**
 * Carrega os dados de referencia de database/seeds/*.sql (gerados por tools/import_*.php).
 * Comandos separados por ";" no fim da linha (mesmo criterio do Migrator); linhas "--" sao comentarios.
 * Os seeds usam REPLACE, entao recarregar e idempotente.
 */
final class SeedLoader
{
    /** @return list<string> arquivos carregados (nome base), em ordem alfabetica */
    public static function loadDir(Database $db, string $dir): array
    {
        $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            self::loadFile($db, $f);
        }
        return array_map('basename', $files);
    }

    public static function loadFile(Database $db, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException('Nao foi possivel ler ' . $path);
        }
        $pdo = $db->pdo();
        foreach (preg_split('/;[ \t]*\r?\n/', $sql . "\n") ?: [] as $chunk) {
            $stmt = trim((string) preg_replace('/^[ \t]*--.*$/m', '', $chunk));
            if ($stmt !== '') {
                $pdo->exec(rtrim($stmt, "; \t\r\n"));
            }
        }
    }
}
