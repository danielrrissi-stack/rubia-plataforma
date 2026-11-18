<?php
declare(strict_types=1);

namespace App\Diary;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Growth\Age;

/** Pedidos do diario de habitos. canSee() e a unica regra de visibilidade. */
final class DiaryRequestRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @param array $user usuario que grava (o papel define o que pode pedir e se o sigilo vale) */
    public function create(int $patientId, array $data, array $user, string $today, Request $r): int
    {
        $errors = [];
        $row = $this->clean($patientId, null, $data, $user, $today, null, $errors);
        if ($errors !== []) {
            throw new InvalidDiaryException($errors);
        }
        $uid = (int) $user['id'];
        $row['secret'] = !empty($data['secret']) && $this->mayMarkSecret($user, $patientId, $today) ? 1 : 0;
        $id = $this->db->insert('diary_requests', ['patient_id' => $patientId, 'created_by' => $uid] + $row);
        $this->audit->record($uid, 'create', 'diary_requests', $id, $patientId, null, $this->find($id), $r);
        return $id;
    }

    public function update(int $id, array $data, array $user, string $today, Request $r): void
    {
        $before = $this->active($id);
        $patientId = (int) $before['patient_id'];
        $errors = [];
        if (!$this->mayEdit($before, $user)) {
            $errors[] = 'Este tipo de pedido é só da médica.';
        } else {
            $row = $this->clean($patientId, $id, $data, $user, $today, $before, $errors);
            if ($errors === [] && $row['type'] !== $before['type'] && $this->hasEntries($id)) {
                $errors[] = 'O tipo não pode mudar depois que há registros.';
            }
        }
        if ($errors !== []) {
            throw new InvalidDiaryException($errors);
        }
        $uid = (int) $user['id'];
        $row['secret'] = $this->secretOnUpdate((int) $before['secret'], $patientId, $user, $data, $today);
        $sets = implode(', ', array_map(fn (string $c): string => $c . ' = ?', array_keys($row)));
        $this->db->run(
            'UPDATE diary_requests SET ' . $sets . ', updated_by = ?, updated_at = NOW() WHERE id = ?',
            [...array_values($row), $uid, $id],
        );
        $this->audit->record($uid, 'update', 'diary_requests', $id, $patientId, $before, $this->find($id), $r);
    }

    public function end(int $id, int $uid, string $today, Request $r): void
    {
        $before = $this->active($id);
        if ((string) $before['start_date'] > $today) {
            throw new InvalidDiaryException(['Pedido que ainda não começou: exclua em vez de encerrar.']);
        }
        if ($before['end_date'] !== null && (string) $before['end_date'] <= $today) {
            throw new InvalidDiaryException(['O pedido já está encerrado.']);
        }
        $this->db->run(
            'UPDATE diary_requests SET end_date = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$today, $uid, $id],
        );
        $this->audit->record($uid, 'end', 'diary_requests', $id, (int) $before['patient_id'], $before, $this->find($id), $r);
    }

    public function delete(int $id, int $uid, Request $r): void
    {
        $before = $this->active($id);
        if ($this->hasEntries($id)) {
            throw new InvalidDiaryException(['Este pedido já tem registros; encerre em vez de excluir.']);
        }
        $this->db->run(
            'UPDATE diary_requests SET deleted_at = NOW(), deleted_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$uid, $uid, $id],
        );
        $this->audit->record($uid, 'delete', 'diary_requests', $id, (int) $before['patient_id'], $before, $this->find($id), $r);
    }

    /** Inclui excluidos. */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM diary_requests WHERE id = ?', [$id]);
    }

    public function hasEntries(int $id): bool
    {
        return $this->db->one('SELECT 1 AS n FROM diary_entries WHERE request_id = ? AND deleted_at IS NULL LIMIT 1', [$id]) !== null;
    }

    public function appliesOn(array $request, string $ymd): bool
    {
        if ($ymd < (string) $request['start_date'] || ($request['end_date'] !== null && $ymd > (string) $request['end_date'])) {
            return false;
        }
        $n = (int) (new \DateTimeImmutable($ymd))->format('N');
        return ((int) $request['weekdays'] & (1 << ($n - 1))) !== 0;
    }

    /** Unico ponto de visibilidade. O vinculo com o paciente e checado pelos controllers. */
    public function canSee(array $request, array $viewer): bool
    {
        if ($request['deleted_at'] !== null) {
            return false;
        }
        return match ((string) $viewer['role']) {
            'medico', 'paciente' => true,
            'responsavel' => (int) $request['secret'] === 0,
            'nutricionista' => (int) $request['secret'] === 0 && in_array((string) $request['type'], DiaryType::NUTRITIONAL, true),
            default => false,
        };
    }

    /** Pedido nao excluido visivel ao papel; null = "nao encontrado". */
    public function visibleTo(int $id, array $viewer): ?array
    {
        $p = $this->find($id);
        return $p !== null && $this->canSee($p, $viewer) ? $p : null;
    }

    public function mayEdit(array $request, array $user): bool
    {
        return match ((string) $user['role']) {
            'medico' => true,
            'nutricionista' => (int) $request['secret'] === 0 && in_array((string) $request['type'], DiaryType::NUTRITIONAL, true),
            default => false,
        };
    }

    /** @return list<array> pedidos visiveis, nao excluidos, cujo periodo cruza [from, to] */
    public function forViewer(int $patientId, array $viewer, string $from, string $to): array
    {
        $rows = $this->db->all(
            'SELECT * FROM diary_requests WHERE patient_id = ? AND deleted_at IS NULL
               AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)
             ORDER BY FIELD(type, ' . $this->typeList() . '), start_date, id',
            [$patientId, $to, $from],
        );
        return array_values(array_filter($rows, fn (array $p): bool => $this->canSee($p, $viewer)));
    }

    /** @return array{current: list<array>, ended: list<array>} */
    public function listForStaff(int $patientId, array $viewer, string $today): array
    {
        $rows = $this->db->all(
            'SELECT * FROM diary_requests WHERE patient_id = ? AND deleted_at IS NULL
             ORDER BY FIELD(type, ' . $this->typeList() . '), start_date, id',
            [$patientId],
        );
        $current = [];
        $ended = [];
        foreach ($rows as $p) {
            if (!$this->canSee($p, $viewer)) {
                continue;
            }
            if ($p['end_date'] !== null && (string) $p['end_date'] < $today) {
                $ended[] = $p;
            } else {
                $current[] = $p;
            }
        }
        usort($ended, fn (array $a, array $b): int => [$b['end_date'], $b['id']] <=> [$a['end_date'], $a['id']]);
        return ['current' => $current, 'ended' => $ended];
    }

    /** Unico predicado de "pode ligar o sigilo": medico e paciente com menos de 18 anos hoje (anos completos). */
    public function mayMarkSecret(array $user, int $patientId, string $today): bool
    {
        if ((string) $user['role'] !== 'medico') {
            return false;
        }
        $patient = $this->db->one('SELECT birth_date FROM patients WHERE id = ?', [$patientId]);
        return $patient !== null && Age::years((string) $patient['birth_date'], $today) < 18;
    }

    /** R6: o sigilo nunca e desligado implicitamente; so e aplicado se o checkbox foi exibido (secret_shown). */
    private function secretOnUpdate(int $current, int $patientId, array $user, array $data, string $today): int
    {
        if (empty($data['secret_shown'])) {
            return $current;
        }
        if (!empty($data['secret'])) {
            return $current === 1 ? 1 : ($this->mayMarkSecret($user, $patientId, $today) ? 1 : 0);
        }
        return (string) $user['role'] === 'medico' ? 0 : $current;
    }

    private function active(int $id): array
    {
        $p = $this->find($id);
        if ($p === null || $p['deleted_at'] !== null) {
            throw new InvalidDiaryException(['Pedido não encontrado.']);
        }
        return $p;
    }

    private function typeList(): string
    {
        return implode(', ', array_map(fn (string $t): string => "'" . $t . "'", DiaryType::TYPES));
    }

    /**
     * Colunas validadas (sem o sigilo). Datas a ate 5 anos de hoje (valor inalterado numa edicao e aceito).
     *
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private function clean(int $patientId, ?int $selfId, array $data, array $user, string $today, ?array $current, array &$errors): array
    {
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, DiaryType::TYPES, true)) {
            $errors[] = 'Escolha o tipo de pedido.';
        } elseif ((string) $user['role'] === 'nutricionista' && !in_array($type, DiaryType::NUTRITIONAL, true)) {
            $errors[] = 'Este tipo de pedido é só da médica.';
        }
        $mask = $this->mask($data['weekdays'] ?? 0);
        if ($mask < 1 || $mask > DiaryType::ALL_DAYS) {
            $errors[] = 'Escolha pelo menos um dia da semana.';
        }
        $start = (string) ($data['start_date'] ?? '');
        if (!$this->isDate($start)) {
            $errors[] = 'Informe a data de início.';
        } elseif (!$this->inWindow($start, $today) && $start !== ($current['start_date'] ?? null)) {
            $errors[] = 'A data de início deve estar a até 5 anos de hoje.';
        }
        $end = trim((string) ($data['end_date'] ?? ''));
        if ($end === '') {
            $end = null;
        } elseif (!$this->isDate($end)) {
            $errors[] = 'A data de término é inválida.';
            $end = null;
        } elseif (!$this->inWindow($end, $today) && $end !== ($current['end_date'] ?? null)) {
            $errors[] = 'A data de término deve estar a até 5 anos de hoje.';
        }
        if ($end !== null && $this->isDate($start) && $end < $start) {
            $errors[] = 'A data de término deve ser igual ou posterior ao início.';
        }
        $guidance = trim((string) ($data['guidance'] ?? ''));
        if (mb_strlen($guidance) > 255) {
            $errors[] = 'A orientação deve ter até 255 caracteres.';
        }
        if ($errors === [] && $this->overlaps($patientId, $selfId, $type, $mask, $start, $end)) {
            $errors[] = 'Já existe um pedido de ' . DiaryType::LABELS[$type] . ' para esses dias.';
        }
        return [
            'type' => $type,
            'weekdays' => $mask,
            'start_date' => $start,
            'end_date' => $end,
            'guidance' => $guidance === '' ? null : $guidance,
        ];
    }

    private function overlaps(int $patientId, ?int $selfId, string $type, int $mask, string $start, ?string $end): bool
    {
        $row = $this->db->one(
            'SELECT 1 AS n FROM diary_requests
             WHERE patient_id = ? AND type = ? AND deleted_at IS NULL AND id <> ? AND (weekdays & ?) <> 0
               AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) LIMIT 1',
            [$patientId, $type, $selfId ?? 0, $mask, $end ?? '9999-12-31', $start],
        );
        return $row !== null;
    }

    /** @param int|string|list<int|string> $v */
    private function mask(mixed $v): int
    {
        if (!is_array($v)) {
            return (int) $v;
        }
        $mask = 0;
        foreach ($v as $n) {
            $n = (int) $n;
            if ($n >= 1 && $n <= 7) {
                $mask |= 1 << ($n - 1);
            }
        }
        return $mask;
    }

    private function inWindow(string $date, string $today): bool
    {
        $t = new \DateTimeImmutable($today);
        return $date >= $t->modify('-5 years')->format('Y-m-d') && $date <= $t->modify('+5 years')->format('Y-m-d');
    }

    private function isDate(mixed $v): bool
    {
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
