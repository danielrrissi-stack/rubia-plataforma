<?php
declare(strict_types=1);

namespace App\Core;

final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** Nomes das migrações ainda não aplicadas, em ordem. */
    public function pending(): array
    {
        $this->ensureTable();
        $applied = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob(rtrim($this->dir, '/\\') . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names);
        return array_values(array_diff($names, $applied));
    }

    /** Aplica as pendentes e devolve os nomes aplicados. */
    public function migrate(): array
    {
        $done = [];
        foreach ($this->pending() as $name) {
            $sql = (string) file_get_contents(rtrim($this->dir, '/\\') . '/' . $name);
            foreach ($this->split($sql) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->run('INSERT INTO schema_migrations (name) VALUES (?)', [$name]);
            $done[] = $name;
        }
        return $done;
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'name VARCHAR(190) NOT NULL PRIMARY KEY, '
            . 'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** Divide por ";" no fim de linha. */
    private function split(string $sql): array
    {
        $parts = preg_split('/;[ \t]*\r?\n/', $sql . "\n") ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $out[] = rtrim($p, "; \t\r\n");
        }
        return $out;
    }
}
