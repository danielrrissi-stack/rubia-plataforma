<?php
declare(strict_types=1);

namespace App\Goals;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/**
 * Cartoes educativos "Para voce ler": a medica revisa e publica; o portal mostra so os publicados da faixa
 * etaria do paciente (anos completos hoje; fora de 3..19 nenhum).
 */
final class EduCardRepository
{
    /** Faixa => [idade minima, idade maxima], na ordem do SET da tabela. */
    public const BANDS = ['3-6' => [3, 6], '7-10' => [7, 10], '11-14' => [11, 14], '15-19' => [15, 19]];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return list<array> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM edu_cards ORDER BY sort_order, id');
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM edu_cards WHERE id = ?', [$id]);
    }

    /** Cria ($id null, como rascunho) ou altera um cartao; a situacao (rascunho/publicado) nao muda aqui. */
    public function save(?int $id, array $data, int $uid, Request $r): int
    {
        $before = null;
        if ($id !== null) {
            $before = $this->find($id);
            if ($before === null) {
                throw new InvalidGoalException(['Cartão não encontrado.']);
            }
        }
        $row = $this->clean($data);
        if ($id === null) {
            $newId = $this->db->insert('edu_cards', $row + ['status' => 'rascunho', 'updated_by' => $uid]);
            $this->db->run('UPDATE edu_cards SET updated_at = NOW() WHERE id = ?', [$newId]);
            $this->audit->record($uid, 'create', 'edu_cards', $newId, null, null, $this->find($newId), $r);
            return $newId;
        }
        $sets = implode(', ', array_map(fn (string $c): string => $c . ' = ?', array_keys($row)));
        $this->db->run(
            'UPDATE edu_cards SET ' . $sets . ', updated_by = ?, updated_at = NOW() WHERE id = ?',
            [...array_values($row), $uid, $id],
        );
        $this->audit->record($uid, 'update', 'edu_cards', $id, null, $before, $this->find($id), $r);
        return $id;
    }

    /** Publica e registra quem aprovou e quando. */
    public function publish(int $id, int $uid, Request $r): void
    {
        $before = $this->existing($id);
        $this->db->run(
            "UPDATE edu_cards SET status = 'publicado', approved_by = ?, approved_at = NOW() WHERE id = ?",
            [$uid, $id],
        );
        $this->audit->record($uid, 'publish', 'edu_cards', $id, null, $before, $this->find($id), $r);
    }

    /** Volta a rascunho (sai do portal); a aprovacao anterior fica so na auditoria. */
    public function unpublish(int $id, int $uid, Request $r): void
    {
        $before = $this->existing($id);
        $this->db->run(
            "UPDATE edu_cards SET status = 'rascunho', approved_by = NULL, approved_at = NULL WHERE id = ?",
            [$id],
        );
        $this->audit->record($uid, 'unpublish', 'edu_cards', $id, null, $before, $this->find($id), $r);
    }

    /** @return list<array> publicados cuja faixa contem a idade, na ordem da medica */
    public function publishedForAge(int $years): array
    {
        $band = self::bandFor($years);
        if ($band === null) {
            return [];
        }
        return $this->db->all(
            "SELECT * FROM edu_cards WHERE status = 'publicado' AND FIND_IN_SET(?, bands) > 0 ORDER BY sort_order, id",
            [$band],
        );
    }

    public static function bandFor(int $years): ?string
    {
        foreach (self::BANDS as $band => [$min, $max]) {
            if ($years >= $min && $years <= $max) {
                return $band;
            }
        }
        return null;
    }

    private function existing(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new InvalidGoalException(['Cartão não encontrado.']);
        }
        return $row;
    }

    private function clean(array $data): array
    {
        $errors = [];
        $title = trim((string) ($data['title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 60) {
            $errors[] = 'O título deve ter de 3 a 60 caracteres.';
        }
        $body = trim(str_replace("\r\n", "\n", (string) ($data['body'] ?? '')));
        if (mb_strlen($body) < 10 || mb_strlen($body) > 600) {
            $errors[] = 'O texto deve ter de 10 a 600 caracteres.';
        }
        $emoji = trim((string) ($data['emoji'] ?? ''));
        if ($emoji === '' || mb_strlen($emoji) > 16) {
            $errors[] = 'Escolha um emoji para o cartão.';
        }
        $raw = is_array($data['bands'] ?? null) ? $data['bands'] : [];
        $bands = array_values(array_filter(
            array_keys(self::BANDS),
            static fn (string $b): bool => in_array($b, $raw, true)
        ));
        if ($bands === [] || count($bands) !== count(array_unique($raw))) {
            $errors[] = 'Escolha ao menos uma faixa etária válida.';
        }
        $orderRaw = $data['sort_order'] ?? 0;
        $order = filter_var($orderRaw === '' ? 0 : $orderRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9999]]);
        if ($order === false) {
            $errors[] = 'A ordem deve ser um número de 0 a 9999.';
        }
        if ($errors !== []) {
            throw new InvalidGoalException($errors);
        }
        return [
            'title' => $title,
            'body' => $body,
            'emoji' => $emoji,
            'bands' => implode(',', $bands),
            'sort_order' => (int) $order,
        ];
    }
}
