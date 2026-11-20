<?php
declare(strict_types=1);

namespace App\Backup;

use App\Core\Database;

/** Dump SQL em PHP puro (sem mysqldump, indisponivel em hospedagem compartilhada). */
final class Dumper
{
    private const BATCH = 500;

    public function __construct(private Database $db)
    {
    }

    /** Grava o dump de todas as tabelas base do banco configurado, em streaming. */
    public function dump(string $file): void
    {
        $fh = fopen($file, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Não foi possível criar o arquivo de dump.');
        }
        $pdo = $this->db->pdo();
        // Leitura consistente entre tabelas (InnoDB, REPEATABLE READ). Usa o PDO direto porque
        // Database::transaction se juntaria a uma transacao aberta. Se ja houver transacao ativa
        // (ex.: testes com DbTestCase), o snapshot e pulado para nao encerra-la: a leitura usa a existente.
        $snapshot = !$pdo->inTransaction();
        try {
            if ($snapshot) {
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            }
            fwrite($fh,"-- Dump Dra. Rubia\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM);
            foreach ($tables as $t) {
                $this->dumpTable($fh, (string) $t[0]);
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            if ($snapshot) {
                $pdo->exec('COMMIT');
            }
            fclose($fh);
        }
    }

    /** @param resource $fh */
    private function dumpTable($fh, string $table): void
    {
        $pdo = $this->db->pdo();
        $q = '`' . str_replace('`', '``', $table) . '`';
        $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(\PDO::FETCH_NUM);
        fwrite($fh, "DROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n\n");

        // Ordena pela chave primaria para paginar de forma estavel sem carregar a tabela inteira.
        $pk = [];
        foreach ($pdo->query("SHOW KEYS FROM $q WHERE Key_name = 'PRIMARY'")->fetchAll() as $k) {
            $pk[(int) $k['Seq_in_index']] = '`' . str_replace('`', '``', (string) $k['Column_name']) . '`';
        }
        ksort($pk);
        $order = $pk === [] ? '' : ' ORDER BY ' . implode(', ', $pk);

        $names = [];
        foreach ($pdo->query("SHOW COLUMNS FROM $q")->fetchAll() as $c) {
            $names[] = '`' . str_replace('`', '``', (string) $c['Field']) . '`';
        }
        $cols = implode(',', $names);

        for ($offset = 0;; $offset += self::BATCH) {
            $rows = $pdo->query("SELECT * FROM $q$order LIMIT " . self::BATCH . " OFFSET $offset")
                ->fetchAll(\PDO::FETCH_NUM);
            if ($rows === []) {
                break;
            }
            $values = [];
            foreach ($rows as $row) {
                $values[] = '(' . implode(',', array_map(
                    fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    $row
                )) . ')';
            }
            fwrite($fh, "INSERT INTO $q ($cols) VALUES\n" . implode(",\n", $values) . ";\n");
            if (count($rows) < self::BATCH) {
                break;
            }
        }
        fwrite($fh, "\n");
    }
}
