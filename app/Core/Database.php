<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private array $cfg)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $this->cfg['host'] ?? '127.0.0.1',
                $this->cfg['name'] ?? ''
            );
            $this->pdo = new PDO($dsn, $this->cfg['user'] ?? '', $this->cfg['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec("SET time_zone = '-03:00'");
        }
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function one(string $sql, array $p = []): ?array
    {
        $row = $this->run($sql, $p)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $p = []): array
    {
        return $this->run($sql, $p)->fetchAll();
    }

    /** Insere uma linha e devolve o id gerado. */
    public function insert(string $table, array $row): int
    {
        $cols = array_map(static fn(string $c): string => '`' . str_replace('`', '', $c) . '`', array_keys($row));
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            str_replace('`', '', $table),
            implode(', ', $cols),
            implode(', ', array_fill(0, count($row), '?'))
        );
        $this->run($sql, array_values($row));
        return (int) $this->pdo()->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
