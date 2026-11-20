<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A hospedagem roda MySQL 5.7 (Percona); o ambiente de testes roda MariaDB.
 * Barra sintaxe que so o MariaDB aceita, para nao passar nos testes e falhar no deploy.
 */
final class MigrationPortabilityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function migrations(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('migrations')]
    public function testNoMariaDbOnlySyntax(string $file): void
    {
        $sql = (string) file_get_contents($file);
        $forbidden = [
            '/\bPERSISTENT\b/i' => 'coluna gerada: use STORED',
            '/\b(ADD|DROP)\s+(COLUMN\s+)?IF\s+(NOT\s+)?EXISTS\b/i' => 'IF [NOT] EXISTS em coluna',
            '/\bRETURNING\b/i' => 'RETURNING',
            '/\bCREATE\s+OR\s+REPLACE\s+TABLE\b/i' => 'CREATE OR REPLACE TABLE',
            '/\bOVER\s*\(/i' => 'funcoes de janela (MySQL 5.7 nao tem)',
            '/^\s*WITH\s+\w+\s+AS\s*\(/im' => 'CTE (MySQL 5.7 nao tem)',
        ];
        foreach ($forbidden as $pattern => $why) {
            $this->assertSame(0, preg_match($pattern, $sql), basename($file) . ': ' . $why);
        }
    }
}
