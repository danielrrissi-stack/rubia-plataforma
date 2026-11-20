<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Trechos de texto curtos e nomeados, inseridos no editor do plano em texto. Exclusao logica. */
final class SnippetRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    public function create(string $title, string $body, int $by, ?Request $r = null): int
    {
        [$title, $body] = $this->clean($title, $body);
        return $this->db->transaction(function () use ($title, $body, $by, $r): int {
            $id = $this->db->insert('text_snippets', ['title' => $title, 'body' => $body, 'created_by' => $by]);
            $this->audit->record($by, 'create', 'text_snippets', $id, null, null, ['title' => $title, 'body' => $body], $r);
            return $id;
        });
    }

    public function update(int $id, string $title, string $body, array $user, ?Request $r = null): void
    {
        $before = $this->mustFind($id);
        $this->assertOwner($before, $user);
        [$title, $body] = $this->clean($title, $body);
        $this->db->transaction(function () use ($id, $title, $body, $before, $user, $r): void {
            $this->db->run('UPDATE text_snippets SET title = ?, body = ?, updated_at = NOW() WHERE id = ?', [$title, $body, $id]);
            $this->audit->record((int) $user['id'], 'update', 'text_snippets', $id, null,
                ['title' => $before['title'], 'body' => $before['body']], ['title' => $title, 'body' => $body], $r);
        });
    }

    public function delete(int $id, array $user, ?Request $r = null): void
    {
        $before = $this->mustFind($id);
        $this->assertOwner($before, $user);
        $this->db->transaction(function () use ($id, $before, $user, $r): void {
            $this->db->run('UPDATE text_snippets SET deleted_at = NOW() WHERE id = ?', [$id]);
            $this->audit->record((int) $user['id'], 'delete', 'text_snippets', $id, null,
                ['title' => $before['title'], 'body' => $before['body']], null, $r);
        });
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM text_snippets WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** @return list<array> nao excluidos, por titulo */
    public function all(): array
    {
        return $this->db->all(
            'SELECT s.*, u.name AS author FROM text_snippets s JOIN users u ON u.id = s.created_by
             WHERE s.deleted_at IS NULL ORDER BY s.title, s.id',
        );
    }

    public static function canModify(array $row, array $user): bool
    {
        return (int) $row['created_by'] === (int) ($user['id'] ?? 0) || (int) ($user['is_admin'] ?? 0) === 1;
    }

    private function assertOwner(array $row, array $user): void
    {
        if (!self::canModify($row, $user)) {
            throw new \RuntimeException('Somente quem criou o trecho ou a administradora pode alterá-lo.');
        }
    }

    private function mustFind(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('Trecho não encontrado.');
        }
        return $row;
    }

    /** @return array{0:string,1:string} */
    private function clean(string $title, string $body): array
    {
        $title = trim(mb_scrub($title, 'UTF-8'));
        $body = trim(PlanText::normalizePaste(mb_scrub($body, 'UTF-8')));
        $len = mb_strlen($title, 'UTF-8');
        if ($len < 2 || $len > 100) {
            throw new \RuntimeException('Informe o título do trecho (2 a 100 caracteres).');
        }
        $blen = mb_strlen($body, 'UTF-8');
        if ($blen < 1 || $blen > 5000) {
            throw new \RuntimeException('Informe o texto do trecho (até 5000 caracteres).');
        }
        return [$title, $body];
    }
}
