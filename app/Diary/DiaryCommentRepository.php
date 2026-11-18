<?php
declare(strict_types=1);

namespace App\Diary;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Comentarios da equipe nos registros. Exclusao logica; so a autora edita e apaga. */
final class DiaryCommentRepository
{
    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private DiaryEntryRepository $entries,
        private DiaryRequestRepository $requests,
    ) {
    }

    public function add(int $entryId, string $body, array $user, Request $r): int
    {
        $entry = $this->staffEntry($entryId, $user);
        $body = $this->cleanBody($body);
        $id = $this->db->insert('diary_comments', ['entry_id' => $entryId, 'body' => $body, 'created_by' => (int) $user['id']]);
        $this->audit->record((int) $user['id'], 'create', 'diary_comments', $id, (int) $entry['patient_id'], null, $this->raw($id), $r);
        return $id;
    }

    public function update(int $commentId, string $body, array $user, Request $r): void
    {
        [$before, $entry] = $this->own($commentId, $user);
        $body = $this->cleanBody($body);
        $this->db->run('UPDATE diary_comments SET body = ?, updated_at = NOW() WHERE id = ?', [$body, $commentId]);
        $this->audit->record((int) $user['id'], 'update', 'diary_comments', $commentId, (int) $entry['patient_id'], $before, $this->raw($commentId), $r);
    }

    public function delete(int $commentId, array $user, Request $r): void
    {
        [$before, $entry] = $this->own($commentId, $user);
        $uid = (int) $user['id'];
        $this->db->run('UPDATE diary_comments SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$uid, $commentId]);
        $this->audit->record($uid, 'delete', 'diary_comments', $commentId, (int) $entry['patient_id'], $before, $this->raw($commentId), $r);
    }

    /**
     * Comentarios nao excluidos por registro (a visibilidade do registro e de quem chama).
     *
     * @param list<int> $entryIds
     * @return array<int, list<array>>
     */
    public function forEntries(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $ids = array_values(array_map('intval', $entryIds));
        $rows = $this->db->all(
            'SELECT c.*, u.name AS author_name FROM diary_comments c JOIN users u ON u.id = c.created_by
              WHERE c.entry_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND c.deleted_at IS NULL
              ORDER BY c.created_at, c.id',
            $ids,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['entry_id']][] = $row;
        }
        return $out;
    }

    /** Registro visivel a quem comenta (medica; nutricionista so se pode editar o pedido). */
    private function staffEntry(int $entryId, array $user): array
    {
        if (!in_array((string) $user['role'], ['medico', 'nutricionista'], true)) {
            throw new InvalidDiaryException(['Só a equipe comenta no diário.']);
        }
        $entry = $this->entries->visibleTo($entryId, $user);
        $request = $entry === null ? null : $this->requests->find((int) $entry['request_id']);
        if ($entry === null || $request === null || !$this->requests->mayEdit($request, $user)) {
            throw new InvalidDiaryException(['Registro não encontrado.']);
        }
        return $entry;
    }

    /** @return array{0: array, 1: array} comentario e registro, se $user e a autora e ainda enxerga o registro */
    private function own(int $commentId, array $user): array
    {
        $c = $this->raw($commentId);
        if ($c === null || $c['deleted_at'] !== null) {
            throw new InvalidDiaryException(['Comentário não encontrado.']);
        }
        if ((int) $c['created_by'] !== (int) $user['id']) {
            throw new InvalidDiaryException(['Só quem escreveu pode alterar o comentário.']);
        }
        return [$c, $this->staffEntry((int) $c['entry_id'], $user)];
    }

    private function cleanBody(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 500) {
            throw new InvalidDiaryException(['O comentário deve ter de 1 a 500 caracteres.']);
        }
        return $body;
    }

    private function raw(int $id): ?array
    {
        return $this->db->one('SELECT * FROM diary_comments WHERE id = ?', [$id]);
    }
}
