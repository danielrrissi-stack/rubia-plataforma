<?php
declare(strict_types=1);

namespace App\Medications;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Growth\Age;

/** Prescricoes com historico de doses. visibleTo()/forViewer() sao a unica regra de visibilidade. */
final class PrescriptionRepository
{
    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @param array $user usuario que grava (o papel define se o sigilo vale) */
    public function create(int $patientId, array $data, array $user, string $today, Request $r): int
    {
        $errors = [];
        $row = $this->cleanPrescription($data, $today, null, $errors);
        $dose = $this->cleanDose($data, $errors);
        if ($errors !== []) {
            throw new InvalidMedicationException($errors);
        }
        $uid = (int) $user['id'];
        $row['secret'] = $this->secretFor($patientId, $user, !empty($data['secret']), $today);
        $id = $this->db->insert('prescriptions', ['patient_id' => $patientId, 'created_by' => $uid] + $row);
        $this->audit->record($uid, 'create', 'prescriptions', $id, $patientId, null, $this->find($id), $r);
        $this->insertDose($id, $patientId, $row['start_date'], $dose, $uid, $r);
        return $id;
    }

    /** Altera so a prescricao; a primeira linha de dose acompanha o inicio (regra R1). */
    public function update(int $id, array $data, array $user, string $today, Request $r): void
    {
        $before = $this->active($id);
        $errors = [];
        $row = $this->cleanPrescription($data, $today, $before, $errors);
        $doses = $this->doses($id);
        if ($errors === [] && count($doses) > 1 && $row['start_date'] > $doses[1]['effective_date']) {
            $errors[] = 'O início não pode ser posterior à segunda alteração de dose já registrada.';
        }
        if ($errors !== []) {
            throw new InvalidMedicationException($errors);
        }
        $uid = (int) $user['id'];
        $patientId = (int) $before['patient_id'];
        $row['secret'] = $this->secretFor($patientId, $user, !empty($data['secret']), $today);
        $sets = implode(', ', array_map(fn (string $c): string => $c . ' = ?', array_keys($row)));
        $this->db->run(
            'UPDATE prescriptions SET ' . $sets . ', updated_by = ?, updated_at = NOW() WHERE id = ?',
            [...array_values($row), $uid, $id],
        );
        if ($doses !== [] && $doses[0]['effective_date'] !== $row['start_date']) {
            $this->db->run('UPDATE prescription_doses SET effective_date = ? WHERE id = ?', [$row['start_date'], $doses[0]['id']]);
        }
        $this->audit->record($uid, 'update', 'prescriptions', $id, $patientId, $before, $this->find($id), $r);
    }

    public function changeDose(int $id, array $data, int $uid, string $today, Request $r): int
    {
        $p = $this->active($id);
        if ($this->status($p, $today) === 'encerrada') {
            throw new InvalidMedicationException(['A prescrição está encerrada.']);
        }
        $errors = [];
        $date = (string) ($data['effective_date'] ?? '');
        $dose = $this->cleanDose($data, $errors);
        if (!$this->isDate($date)) {
            $errors[] = 'Informe a data da nova dose.';
        } else {
            $doses = $this->doses($id);
            $last = $doses === [] ? (string) $p['start_date'] : (string) end($doses)['effective_date'];
            if ($date < $last || $date < $p['start_date']) {
                $errors[] = 'A data da nova dose deve ser igual ou posterior à última alteração.';
            } elseif ($date > max($today, (string) $p['start_date'])) {
                $errors[] = 'Não é possível registrar dose futura além do início da prescrição.';
            }
        }
        $reason = $this->text($data['reason'] ?? '', 200, 'O motivo', $errors);
        if ($errors !== []) {
            throw new InvalidMedicationException($errors);
        }
        return $this->insertDose($id, (int) $p['patient_id'], $date, $dose + ['reason' => $reason === '' ? null : $reason], $uid, $r);
    }

    public function end(int $id, string $endDate, ?string $reason, int $uid, string $today, Request $r): void
    {
        $before = $this->active($id);
        $status = $this->status($before, $today);
        if ($status === 'futura') {
            throw new InvalidMedicationException(['Prescrição futura não pode ser encerrada; exclua-a.']);
        }
        if ($status === 'encerrada') {
            throw new InvalidMedicationException(['A prescrição já está encerrada.']);
        }
        if (!$this->isDate($endDate) || $endDate < $before['start_date']) {
            throw new InvalidMedicationException(['A data de término deve ser igual ou posterior ao início.']);
        }
        if ($endDate > $today) {
            throw new InvalidMedicationException(['A data de término não pode ser futura.']);
        }
        $errors = [];
        $reason = $this->text($reason ?? '', 200, 'O motivo', $errors);
        if ($errors !== []) {
            throw new InvalidMedicationException($errors);
        }
        $this->db->run(
            'UPDATE prescriptions SET end_date = ?, end_reason = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$endDate, $reason === '' ? null : $reason, $uid, $id],
        );
        $this->audit->record($uid, 'end', 'prescriptions', $id, (int) $before['patient_id'], $before, $this->find($id), $r);
    }

    public function delete(int $id, int $uid, Request $r): void
    {
        $before = $this->active($id);
        $this->db->run(
            'UPDATE prescriptions SET deleted_at = NOW(), deleted_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$uid, $uid, $id],
        );
        $this->audit->record($uid, 'delete', 'prescriptions', $id, (int) $before['patient_id'], $before, $this->find($id), $r);
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM prescriptions WHERE id = ?', [$id]);
    }

    /** @return list<array> */
    public function doses(int $id): array
    {
        return $this->db->all(
            'SELECT d.*, u.name AS author_name FROM prescription_doses d JOIN users u ON u.id = d.created_by
             WHERE d.prescription_id = ? ORDER BY d.effective_date, d.id',
            [$id],
        );
    }

    /** Linha de dose vigente na data (maior effective_date <= ymd, desempate pelo id). */
    public function doseOn(int $id, string $ymd): ?array
    {
        return $this->db->one(
            'SELECT d.*, u.name AS author_name FROM prescription_doses d JOIN users u ON u.id = d.created_by
             WHERE d.prescription_id = ? AND d.effective_date <= ? ORDER BY d.effective_date DESC, d.id DESC LIMIT 1',
            [$id, $ymd],
        );
    }

    /** @return 'futura'|'ativa'|'encerrada' */
    public function status(array $p, string $today): string
    {
        if ($p['start_date'] > $today) {
            return 'futura';
        }
        return $p['end_date'] !== null && $p['end_date'] < $today ? 'encerrada' : 'ativa';
    }

    /**
     * Prescricoes ativas com reavaliacao ate hoje + $days (as vencidas inclusas), da mais antiga a mais proxima.
     * @return list<array>
     */
    public function reviewsDue(string $today, int $days = 7): array
    {
        $limit = (new \DateTimeImmutable($today))->modify('+' . $days . ' days')->format('Y-m-d');
        return $this->db->all(
            'SELECT p.*, pt.full_name AS patient_name FROM prescriptions p
             JOIN patients pt ON pt.id = p.patient_id AND pt.deleted_at IS NULL
             WHERE p.deleted_at IS NULL AND p.start_date <= ? AND (p.end_date IS NULL OR p.end_date >= ?)
               AND p.next_review_date IS NOT NULL AND p.next_review_date <= ?
             ORDER BY p.next_review_date, p.id',
            [$today, $today, $limit],
        );
    }

    /** Prescricao nao excluida visivel ao papel; null = "nao encontrada". */
    public function visibleTo(int $id, array $viewer): ?array
    {
        $p = $this->find($id);
        if ($p === null || $p['deleted_at'] !== null) {
            return null;
        }
        $ok = match ((string) $viewer['role']) {
            'medico', 'paciente' => true,
            'responsavel' => (int) $p['secret'] === 0,
            default => false,
        };
        return $ok ? $p : null;
    }

    /** @return list<array> prescricoes visiveis, cada uma com `current` e `status` */
    public function forViewer(int $patientId, array $viewer, string $today): array
    {
        $role = (string) $viewer['role'];
        if (!in_array($role, ['medico', 'paciente', 'responsavel'], true)) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT * FROM prescriptions WHERE patient_id = ? AND deleted_at IS NULL' . ($role === 'responsavel' ? ' AND secret = 0' : ''),
            [$patientId],
        );
        foreach ($rows as &$p) {
            $p['status'] = $this->status($p, $today);
            $p['current'] = $this->doseOn((int) $p['id'], $today) ?? ($this->doses((int) $p['id'])[0] ?? null);
        }
        unset($p);
        usort($rows, function (array $a, array $b): int {
            $ea = $a['status'] === 'encerrada';
            $eb = $b['status'] === 'encerrada';
            if ($ea !== $eb) {
                return $ea <=> $eb;
            }
            return $ea
                ? [$b['end_date'], $b['id']] <=> [$a['end_date'], $a['id']]
                : [$a['start_date'], $a['id']] <=> [$b['start_date'], $b['id']];
        });
        return $rows;
    }

    /** @return list<string> */
    public function drugNames(): array
    {
        return array_column($this->db->all(
            'SELECT DISTINCT drug_name FROM prescriptions WHERE deleted_at IS NULL ORDER BY drug_name'
        ), 'drug_name');
    }

    private function insertDose(int $id, int $patientId, string $date, array $dose, int $uid, Request $r): int
    {
        $row = [
            'prescription_id' => $id, 'effective_date' => $date, 'dose' => $dose['dose'], 'frequency' => $dose['frequency'],
            'instructions' => $dose['instructions'], 'reason' => $dose['reason'] ?? null, 'created_by' => $uid,
        ];
        $doseId = $this->db->insert('prescription_doses', $row);
        $this->audit->record($uid, 'create', 'prescription_doses', $doseId, $patientId, null, $row, $r);
        return $doseId;
    }

    private function active(int $id): array
    {
        $p = $this->find($id);
        if ($p === null || $p['deleted_at'] !== null) {
            throw new InvalidMedicationException(['Prescrição não encontrada.']);
        }
        return $p;
    }

    /** Sigilo so por medico e so para menor de 18 anos hoje (anos completos; months() < 216 erra no aniversario por dias bissextos). */
    private function secretFor(int $patientId, array $user, bool $wanted, string $today): int
    {
        if (!$wanted || (string) $user['role'] !== 'medico') {
            return 0;
        }
        $patient = $this->db->one('SELECT birth_date FROM patients WHERE id = ?', [$patientId]);
        return $patient !== null && Age::years((string) $patient['birth_date'], $today) < 18 ? 1 : 0;
    }

    /**
     * Colunas validadas de prescriptions. Datas a ate 5 anos de hoje (valor inalterado numa edicao e aceito).
     *
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private function cleanPrescription(array $data, string $today, ?array $current, array &$errors): array
    {
        $name = $this->text($data['drug_name'] ?? '', 120, 'O medicamento', $errors);
        if ($name === '') {
            $errors[] = 'Informe o medicamento.';
        }
        $route = (string) ($data['route'] ?? '');
        if (!in_array($route, Via::ROUTES, true)) {
            $errors[] = 'Escolha a via de administração.';
        }
        $start = (string) ($data['start_date'] ?? '');
        if (!$this->isDate($start)) {
            $errors[] = 'Informe a data de início.';
        } elseif (!$this->inWindow($start, $today) && $start !== ($current['start_date'] ?? null)) {
            $errors[] = 'A data de início deve estar a até 5 anos de hoje.';
        }
        $end = $this->optionalDate($data['end_date'] ?? '', 'A data de término', $today, $current['end_date'] ?? null, $errors);
        if ($end !== null && $this->isDate($start) && $end < $start) {
            $errors[] = 'A data de término deve ser igual ou posterior ao início.';
        }
        $review = $this->optionalDate($data['next_review_date'] ?? '', 'A data da próxima revisão', $today, $current['next_review_date'] ?? null, $errors);
        $notes = $this->text($data['notes'] ?? '', 2000, 'As observações internas', $errors);
        return [
            'drug_name' => $name,
            'route' => $route,
            'start_date' => $start,
            'end_date' => $end,
            'next_review_date' => $review,
            'family_logs' => !empty($data['family_logs']) ? 1 : 0,
            'notes' => $notes === '' ? null : $notes,
        ];
    }

    /**
     * @param list<string> $errors
     * @return array{dose:string, frequency:string, instructions:?string}
     */
    private function cleanDose(array $data, array &$errors): array
    {
        $dose = $this->text($data['dose'] ?? '', 60, 'A dose', $errors);
        if ($dose === '') {
            $errors[] = 'Informe a dose.';
        }
        $freq = $this->text($data['frequency'] ?? '', 120, 'A frequência', $errors);
        if ($freq === '') {
            $errors[] = 'Informe a frequência.';
        }
        $instr = $this->text($data['instructions'] ?? '', 1000, 'A orientação', $errors);
        return ['dose' => $dose, 'frequency' => $freq, 'instructions' => $instr === '' ? null : $instr];
    }

    /** @param list<string> $errors */
    private function text(mixed $v, int $max, string $label, array &$errors): string
    {
        $s = trim((string) $v);
        if (mb_strlen($s) > $max) {
            $errors[] = $label . ' deve ter até ' . $max . ' caracteres.';
        }
        return $s;
    }

    /** @param list<string> $errors */
    private function optionalDate(mixed $v, string $label, string $today, ?string $unchanged, array &$errors): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (!$this->isDate($s)) {
            $errors[] = $label . ' é inválida.';
            return null;
        }
        if (!$this->inWindow($s, $today) && $s !== $unchanged) {
            $errors[] = $label . ' deve estar a até 5 anos de hoje.';
        }
        return $s;
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
