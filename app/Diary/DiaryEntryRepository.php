<?php
declare(strict_types=1);

namespace App\Diary;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Registros da familia no diario. A visibilidade vem sempre de DiaryRequestRepository::canSee(). */
final class DiaryEntryRepository
{
    /** Dias para tras em que a familia ainda pode registrar, corrigir e apagar. */
    private const WINDOW_DAYS = 7;

    private const COLUMNS = ['meal', 'meal_time', 'description', 'glasses', 'slept_at', 'woke_at', 'sleep_quality', 'activity', 'minutes'];

    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private DiaryRequestRepository $requests,
        private DiaryPhotoStore $photos,
    ) {
    }

    /**
     * Registra um dia. Em tipos de um por dia, regrava o registro existente (ultimo valor e ultimo autor).
     *
     * @param array|null $photo arquivo no formato de $_FILES (so alimentacao)
     */
    public function record(int $requestId, string $day, array $data, ?array $photo, array $user, string $today, Request $r): int
    {
        $this->requireFamily($user);
        $request = $this->requests->find($requestId);
        if ($request === null || !$this->requests->canSee($request, $user)) {
            throw new InvalidDiaryException(['Pedido não encontrado.']);
        }
        $this->checkDay($request, $day, $today, true);
        $cols = $this->clean((string) $request['type'], $data);

        $stored = null;
        if ($photo !== null && (string) $request['type'] === 'alimentacao') {
            $stored = $this->photos->store($photo);
        }
        try {
            return $this->db->transaction(function () use ($requestId, $day, $cols, $stored, $user, $today, $r): int {
                $locked = $this->db->one('SELECT * FROM diary_requests WHERE id = ? FOR UPDATE', [$requestId]);
                if ($locked === null || !$this->requests->canSee($locked, $user)) {
                    throw new InvalidDiaryException(['Pedido não encontrado.']);
                }
                $this->checkDay($locked, $day, $today, true);
                $uid = (int) $user['id'];
                $photoCols = $stored === null ? [] : ['photo_path' => $stored['path'], 'photo_mime' => $stored['mime'], 'photo_size' => $stored['size']];
                if (in_array((string) $locked['type'], DiaryType::ONE_PER_DAY, true)) {
                    $existing = $this->db->one(
                        'SELECT * FROM diary_entries WHERE request_id = ? AND day = ? AND deleted_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE',
                        [$requestId, $day],
                    );
                    if ($existing !== null) {
                        $row = $cols + $photoCols + ['recorded_by' => $uid, 'recorded_by_role' => (string) $user['role']];
                        $this->write((int) $existing['id'], $row, $uid);
                        $this->audit->record($uid, 'update', 'diary_entries', (int) $existing['id'], (int) $locked['patient_id'], $existing, $this->raw((int) $existing['id']), $r);
                        return (int) $existing['id'];
                    }
                }
                $id = $this->db->insert('diary_entries', [
                    'request_id' => $requestId, 'day' => $day, 'recorded_by' => $uid, 'recorded_by_role' => (string) $user['role'],
                ] + $cols + $photoCols);
                $this->audit->record($uid, 'create', 'diary_entries', $id, (int) $locked['patient_id'], null, $this->raw($id), $r);
                return $id;
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->photos->discard($stored['path']);
            }
            throw $e;
        }
    }

    /** Foto nova substitui a anterior (o arquivo antigo fica no disco); $removePhoto limpa sem substituir. */
    public function update(int $entryId, array $data, ?array $photo, bool $removePhoto, array $user, string $today, Request $r): void
    {
        $before = $this->editable($entryId, $user, $today);
        $cols = $this->clean((string) $before['type'], $data);
        $stored = null;
        if ($photo !== null && (string) $before['type'] === 'alimentacao') {
            $stored = $this->photos->store($photo);
        }
        if ($stored !== null) {
            $cols += ['photo_path' => $stored['path'], 'photo_mime' => $stored['mime'], 'photo_size' => $stored['size']];
        } elseif ($removePhoto) {
            $cols += ['photo_path' => null, 'photo_mime' => null, 'photo_size' => null];
        }
        try {
            $this->db->transaction(function () use ($entryId, $cols, $user, $before, $r): void {
                $uid = (int) $user['id'];
                $this->write($entryId, $cols, $uid);
                $this->audit->record($uid, 'update', 'diary_entries', $entryId, (int) $before['patient_id'], $before, $this->raw($entryId), $r);
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->photos->discard($stored['path']);
            }
            throw $e;
        }
    }

    public function remove(int $entryId, array $user, string $today, Request $r): void
    {
        $before = $this->editable($entryId, $user, $today);
        $uid = (int) $user['id'];
        $this->db->transaction(function () use ($entryId, $uid, $before, $r): void {
            $this->db->run(
                'UPDATE diary_entries SET deleted_at = NOW(), deleted_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
                [$uid, $uid, $entryId],
            );
            $this->audit->record($uid, 'delete', 'diary_entries', $entryId, (int) $before['patient_id'], $before, $this->raw($entryId), $r);
        });
    }

    /** Registro nao excluido, com patient_id, type e secret do pedido. */
    public function find(int $entryId): ?array
    {
        $row = $this->raw($entryId);
        return $row !== null && $row['deleted_at'] === null ? $row : null;
    }

    /** Registro e pedido nao excluidos e visiveis ao papel; null = "nao encontrado". */
    public function visibleTo(int $entryId, array $viewer): ?array
    {
        $row = $this->find($entryId);
        return $row !== null && $this->requests->canSee($this->requestOf($row), $viewer) ? $row : null;
    }

    /** @return list<array> visiveis e nao excluidos, por dia, refeicao, horario e id */
    public function forRange(int $patientId, array $viewer, string $from, string $to): array
    {
        $rows = $this->db->all(
            'SELECT e.*, r.patient_id, r.type, r.secret, r.deleted_at AS request_deleted_at, u.name AS recorder_name
               FROM diary_entries e
               JOIN diary_requests r ON r.id = e.request_id
               JOIN users u ON u.id = e.recorded_by
              WHERE r.patient_id = ? AND e.day BETWEEN ? AND ? AND e.deleted_at IS NULL AND r.deleted_at IS NULL
              ORDER BY e.day, FIELD(e.meal, ' . $this->mealList() . '), e.meal_time, e.id',
            [$patientId, $from, $to],
        );
        return array_values(array_filter($rows, fn (array $e): bool => $this->requests->canSee($this->requestOf($e), $viewer)));
    }

    private function requireFamily(array $user): void
    {
        if (!in_array((string) $user['role'], ['paciente', 'responsavel'], true)) {
            throw new InvalidDiaryException(['Só a família anota o diário.']);
        }
    }

    /** Registro que $user pode corrigir/apagar hoje (janela, autoria e visibilidade). */
    private function editable(int $entryId, array $user, string $today): array
    {
        $this->requireFamily($user);
        $row = $this->visibleTo($entryId, $user);
        if ($row === null) {
            throw new InvalidDiaryException(['Registro não encontrado.']);
        }
        $this->checkWindow((string) $row['day'], $today);
        if (!in_array((string) $row['type'], DiaryType::ONE_PER_DAY, true) && (int) $row['recorded_by'] !== (int) $user['id']) {
            throw new InvalidDiaryException(['Só quem anotou pode alterar este registro.']);
        }
        return $row;
    }

    private function checkDay(array $request, string $day, string $today, bool $needsApplies): void
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($d === false || $d->format('Y-m-d') !== $day) {
            throw new InvalidDiaryException(['Informe um dia válido.']);
        }
        if ((string) $request['type'] === 'medidas') {
            throw new InvalidDiaryException(['Peso e medidas não têm registro no diário.']);
        }
        $this->checkWindow($day, $today);
        if ($needsApplies && !$this->requests->appliesOn($request, $day)) {
            throw new InvalidDiaryException(['Este dia não está entre os pedidos da equipe.']);
        }
    }

    private function checkWindow(string $day, string $today): void
    {
        if ($day > $today) {
            throw new InvalidDiaryException(['Não dá para anotar dias futuros.']);
        }
        if ($day < (new \DateTimeImmutable($today))->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d')) {
            throw new InvalidDiaryException(['Só dá para anotar até ' . self::WINDOW_DAYS . ' dias atrás.']);
        }
    }

    /**
     * Colunas do tipo, validadas; as demais ficam nulas (campos de outros tipos sao ignorados).
     *
     * @return array<string,mixed>
     */
    private function clean(string $type, array $data): array
    {
        $cols = array_fill_keys(self::COLUMNS, null);
        $errors = [];
        switch ($type) {
            case 'alimentacao':
                $meal = (string) ($data['meal'] ?? '');
                if (!in_array($meal, DiaryType::MEALS, true)) {
                    $errors[] = 'Escolha a refeição.';
                }
                $time = trim((string) ($data['meal_time'] ?? ''));
                if ($time !== '' && !$this->isTime($time)) {
                    $errors[] = 'O horário da refeição é inválido.';
                }
                $desc = trim((string) ($data['description'] ?? ''));
                if ($desc === '') {
                    $errors[] = 'Conte o que comeu.';
                } elseif (mb_strlen($desc) > 1000) {
                    $errors[] = 'O que comeu deve ter até 1000 caracteres.';
                }
                $cols['meal'] = $meal;
                $cols['meal_time'] = $time === '' ? null : $time . ':00';
                $cols['description'] = $desc;
                break;
            case 'agua':
                $cols['glasses'] = $this->number($data['glasses'] ?? null, 0, 30, 'Informe quantos copos (de 0 a 30).', true, $errors);
                break;
            case 'sono':
                $slept = trim((string) ($data['slept_at'] ?? ''));
                $woke = trim((string) ($data['woke_at'] ?? ''));
                if (!$this->isTime($slept) || !$this->isTime($woke)) {
                    $errors[] = 'Informe a hora de dormir e a de acordar.';
                } elseif ($slept === $woke) {
                    $errors[] = 'O horário de acordar deve ser diferente do de dormir.';
                }
                $cols['slept_at'] = $slept . ':00';
                $cols['woke_at'] = $woke . ':00';
                $cols['sleep_quality'] = $this->number($data['sleep_quality'] ?? null, 1, 3, 'Escolha como foi o sono (1 a 3).', false, $errors);
                break;
            case 'atividade':
                $activity = trim((string) ($data['activity'] ?? ''));
                if ($activity === '') {
                    $errors[] = 'Conte o que fez.';
                } elseif (mb_strlen($activity) > 120) {
                    $errors[] = 'A atividade deve ter até 120 caracteres.';
                }
                $cols['activity'] = $activity;
                $cols['minutes'] = $this->number($data['minutes'] ?? null, 1, 600, 'Os minutos devem ficar entre 1 e 600.', false, $errors);
                break;
            case 'tela':
                $cols['minutes'] = $this->number($data['minutes'] ?? null, 0, 1440, 'Informe os minutos de tela (de 0 a 1440).', true, $errors);
                break;
        }
        if ($errors !== []) {
            throw new InvalidDiaryException($errors);
        }
        return $cols;
    }

    /** @param list<string> $errors */
    private function number(mixed $v, int $min, int $max, string $message, bool $required, array &$errors): ?int
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            if ($required) {
                $errors[] = $message;
            }
            return null;
        }
        if (!ctype_digit($s) || (int) $s < $min || (int) $s > $max) {
            $errors[] = $message;
            return null;
        }
        return (int) $s;
    }

    private function isTime(string $v): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
    }

    private function write(int $id, array $cols, int $uid): void
    {
        $sets = implode(', ', array_map(fn (string $c): string => '`' . $c . '` = ?', array_keys($cols)));
        $this->db->run(
            'UPDATE diary_entries SET ' . $sets . ', updated_by = ?, updated_at = NOW() WHERE id = ?',
            [...array_values($cols), $uid, $id],
        );
    }

    /** Inclui excluidos. */
    private function raw(int $entryId): ?array
    {
        return $this->db->one(
            'SELECT e.*, r.patient_id, r.type, r.secret, r.deleted_at AS request_deleted_at
               FROM diary_entries e JOIN diary_requests r ON r.id = e.request_id WHERE e.id = ?',
            [$entryId],
        );
    }

    /** Campos do pedido que canSee() usa, a partir de uma linha de registro. */
    private function requestOf(array $entry): array
    {
        return ['deleted_at' => $entry['request_deleted_at'], 'secret' => $entry['secret'], 'type' => $entry['type']];
    }

    private function mealList(): string
    {
        return implode(', ', array_map(fn (string $m): string => "'" . $m . "'", DiaryType::MEALS));
    }
}
