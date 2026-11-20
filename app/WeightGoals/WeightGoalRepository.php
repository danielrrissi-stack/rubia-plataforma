<?php
declare(strict_types=1);

namespace App\WeightGoals;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/** Metas de peso: uma ativa por paciente; trocar encerra a anterior ('substituida'); nada e apagado. */
final class WeightGoalRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM weight_goals WHERE id = ?', [$id]);
    }

    public function active(int $patientId): ?array
    {
        return $this->db->one('SELECT * FROM weight_goals WHERE patient_id = ? AND ended_at IS NULL', [$patientId]);
    }

    /** @return list<array> todas as metas do paciente, mais recente primeiro */
    public function history(int $patientId): array
    {
        return $this->db->all('SELECT * FROM weight_goals WHERE patient_id = ? ORDER BY created_at DESC, id DESC', [$patientId]);
    }

    /**
     * @param array{kind:string,start_on:string,start_value:float,target_value:float,target_date:string,notes:?string} $d
     */
    public function create(int $patientId, array $d, array $patient, string $today, int $uid, Request $r): int
    {
        $row = $this->clean($d, $patient, $today);
        return $this->db->transaction(function () use ($patientId, $row, $uid, $r): int {
            // trava a linha do paciente: dois envios ao mesmo tempo (clique duplo) passam um de cada vez
            $this->db->one('SELECT id FROM patients WHERE id = ? FOR UPDATE', [$patientId]);
            $current = $this->active($patientId);
            if ($current !== null && self::same($current, $row)) {
                return (int) $current['id']; // a mesma meta enviada de novo: nada muda
            }
            if ($current !== null) {
                $this->close($current, 'substituida', $uid, $r);
            }
            $id = $this->db->insert('weight_goals', ['patient_id' => $patientId, 'created_by' => $uid] + $row);
            $this->audit->record($uid, 'create', 'weight_goals', $id, $patientId, null, $this->find($id), $r);
            return $id;
        });
    }

    public function end(int $goalId, int $uid, Request $r): void
    {
        $goal = $this->find($goalId);
        if ($goal === null || $goal['ended_at'] !== null) {
            throw new InvalidWeightGoalException(['Esta meta já foi encerrada.']);
        }
        $this->close($goal, 'encerrada', $uid, $r);
    }

    private function close(array $goal, string $reason, int $uid, Request $r): void
    {
        $this->db->run(
            'UPDATE weight_goals SET ended_at = NOW(), ended_by = ?, end_reason = ? WHERE id = ? AND ended_at IS NULL',
            [$uid, $reason, (int) $goal['id']],
        );
        $this->audit->record($uid, 'end', 'weight_goals', (int) $goal['id'], (int) $goal['patient_id'], $goal, $this->find((int) $goal['id']), $r);
    }

    private static function same(array $goal, array $row): bool
    {
        foreach (['kind', 'start_on', 'start_value', 'target_value', 'target_date', 'notes'] as $k) {
            $a = $goal[$k] === null ? null : (string) $goal[$k];
            $b = $row[$k] === null ? null : (string) $row[$k];
            if (in_array($k, ['start_value', 'target_value'], true) && $a !== null && $b !== null) {
                [$a, $b] = [number_format((float) $a, 3, '.', ''), number_format((float) $b, 3, '.', '')];
            }
            if ($a !== $b) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> linha pronta para gravar */
    private function clean(array $d, array $patient, string $today): array
    {
        $errors = [];
        $kind = (string) ($d['kind'] ?? '');
        $birth = (string) $patient['birth_date'];
        if (!in_array($kind, WeightGoalKind::allowed($birth, $today), true)) {
            $errors[] = 'Este tipo de meta não está disponível para a idade do paciente.';
        }
        $target = $d['target_value'] ?? null;
        $start = $d['start_value'] ?? null;
        if (!is_float($target) && !is_int($target)) {
            $errors[] = 'Informe o valor da meta.';
        } elseif ($kind === WeightGoalKind::PESO && ($target < 20 || $target > 300)) {
            $errors[] = 'A meta de peso deve estar entre 20 e 300 kg.';
        } elseif ($kind === WeightGoalKind::IMC_Z && ($target < -3 || $target > 5)) {
            $errors[] = 'A meta de escore-z deve estar entre −3 e +5.';
        } elseif ($start !== null && round((float) $target, 3) === round((float) $start, 3)) {
            $errors[] = 'A meta deve ser diferente do valor atual.';
        }
        $date = (string) ($d['target_date'] ?? '');
        $max = (new \DateTimeImmutable($today))->modify('+5 years')->format('Y-m-d');
        if (!$this->isDate($date)) {
            $errors[] = 'Informe o prazo da meta.';
        } elseif ($date <= $today) {
            $errors[] = 'O prazo deve ser depois de hoje.';
        } elseif ($date > $max) {
            $errors[] = 'O prazo pode ser de no máximo 5 anos.';
        } elseif ($kind === WeightGoalKind::IMC_Z && $date > WeightGoalKind::zDeadlineLimit($birth)) {
            $errors[] = 'Na meta em escore-z, o prazo vai até ' . fmt_date(WeightGoalKind::zDeadlineLimit($birth))
                . ' (fim das curvas da OMS). Para um prazo maior, use a meta em kg.';
        }
        $startOn = (string) ($d['start_on'] ?? '');
        if ($start === null || !$this->isDate($startOn)) {
            $errors[] = 'Registre uma medição antes de definir a meta.';
        }
        $notes = trim((string) ($d['notes'] ?? ''));
        if (mb_strlen($notes) > 500) {
            $errors[] = 'As observações podem ter no máximo 500 caracteres.';
        }
        if ($errors !== []) {
            throw new InvalidWeightGoalException($errors);
        }
        return [
            'kind' => $kind,
            'start_on' => $startOn,
            'start_value' => number_format((float) $start, 3, '.', ''),
            'target_value' => number_format((float) $target, 3, '.', ''),
            'target_date' => $date,
            'notes' => $notes === '' ? null : $notes,
        ];
    }

    private function isDate(string $ymd): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $d !== false && $d->format('Y-m-d') === $ymd;
    }
}
