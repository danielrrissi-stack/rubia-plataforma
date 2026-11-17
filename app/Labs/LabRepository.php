<?php
declare(strict_types=1);

namespace App\Labs;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Patients\PatientRepository;

/** Coletas de exames laboratoriais: resultados, liberacao para a familia, evolucao e serie historica. */
final class LabRepository
{
    private const MAX_ABS = 99999999.0; // limite do DECIMAL(12,4)

    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private LabCatalogRepository $catalog,
        private PatientRepository $patients,
    ) {
    }

    /**
     * @param array{collected_on?:mixed,lab_name?:mixed,notes?:mixed} $data
     * @param array<int|string,string> $values test_id => valor digitado
     */
    public function create(int $patientId, array $data, array $values, int $uid, Request $r): int
    {
        $patient = $this->patients->find($patientId);
        if ($patient === null) {
            throw new \RuntimeException('Paciente não encontrado.');
        }
        [$row, $results] = $this->clean($patient, $data, $values);
        return $this->db->transaction(function () use ($patientId, $row, $results, $uid, $r): int {
            $id = $this->db->insert('lab_collections', ['patient_id' => $patientId, 'created_by' => $uid] + $row);
            $this->insertResults($id, $results);
            $this->audit->record($uid, 'create', 'lab_collections', $id, $patientId, null, $this->view($this->find($id)), $r);
            return $id;
        });
    }

    /** @param array<int|string,string> $values */
    public function update(int $collectionId, array $data, array $values, int $uid, Request $r): void
    {
        $this->db->transaction(function () use ($collectionId, $data, $values, $uid, $r): void {
            $before = $this->lock($collectionId);
            if ($before === null || $before['deleted_at'] !== null) {
                throw new InvalidLabException(['Coleta não encontrada.']);
            }
            $patient = $this->patients->find((int) $before['patient_id']);
            if ($patient === null) {
                throw new \RuntimeException('Paciente não encontrado.');
            }
            [$row, $results] = $this->clean($patient, $data, $values);
            $this->db->run(
                'UPDATE lab_collections SET collected_on = ?, lab_name = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
                [$row['collected_on'], $row['lab_name'], $row['notes'], $uid, $collectionId],
            );
            $this->db->run('DELETE FROM lab_results WHERE collection_id = ?', [$collectionId]);
            $this->insertResults($collectionId, $results);
            $this->audit->record(
                $uid, 'update', 'lab_collections', $collectionId, (int) $before['patient_id'],
                $this->view($before), $this->view($this->find($collectionId)), $r,
            );
        });
    }

    public function delete(int $collectionId, int $uid, Request $r): void
    {
        $this->change($collectionId, $uid, $r, 'delete', 'UPDATE lab_collections SET deleted_at = NOW(), deleted_by = ? WHERE id = ?');
    }

    public function release(int $collectionId, int $uid, Request $r): void
    {
        $this->change($collectionId, $uid, $r, 'release', 'UPDATE lab_collections SET released_at = NOW(), released_by = ? WHERE id = ?');
    }

    public function unrelease(int $collectionId, int $uid, Request $r): void
    {
        $this->change($collectionId, $uid, $r, 'unrelease', 'UPDATE lab_collections SET released_at = NULL, released_by = NULL, updated_by = ? WHERE id = ?');
    }

    /** Inclui coleta excluida. @return array|null coleta + results */
    public function find(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM lab_collections WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $row['results'] = $this->resultsOf([$id])[$id] ?? [];
        return $row;
    }

    /** @return list<array> */
    public function collections(int $patientId, bool $releasedOnly = false): array
    {
        $rows = $this->db->all(
            'SELECT * FROM lab_collections WHERE patient_id = ? AND deleted_at IS NULL'
            . ($releasedOnly ? ' AND released_at IS NOT NULL' : '')
            . ' ORDER BY collected_on DESC, id DESC',
            [$patientId],
        );
        return $this->withResults($rows, true);
    }

    /**
     * @return array{collections:list<array>,total:int,rows:list<array>,homa:?array}
     */
    public function evolution(int $patientId, ?int $limit = 6): array
    {
        $patient = $this->patients->find($patientId);
        $all = $this->db->all(
            'SELECT id, collected_on, released_at FROM lab_collections WHERE patient_id = ? AND deleted_at IS NULL
             ORDER BY collected_on DESC, id DESC',
            [$patientId],
        );
        $total = count($all);
        $shown = $limit === null ? $all : array_slice($all, 0, max(0, $limit));
        $collections = [];
        foreach ($shown as $c) {
            $collections[] = ['id' => (int) $c['id'], 'collected_on' => (string) $c['collected_on'], 'released' => $c['released_at'] !== null];
        }
        $ids = array_column($collections, 'id');
        $results = $ids === [] ? [] : $this->resultsOf($ids);
        $dates = array_column($collections, 'collected_on', 'id');
        $ranges = $this->catalog->rangesByTest();

        $byTest = [];
        foreach ($results as $cid => $list) {
            foreach ($list as $res) {
                $byTest[$res['test_id']][$cid] = $res;
            }
        }
        $rows = [];
        $testsByCode = [];
        foreach ($this->catalog->tests(false) as $t) {
            $testsByCode[(string) $t['code']] = $t;
            $tid = (int) $t['id'];
            if (!isset($byTest[$tid])) {
                continue;
            }
            $cells = [];
            foreach ($byTest[$tid] as $cid => $res) {
                $cells[$cid] = $this->cell($t, $res, $patient, $dates[$cid], $ranges[$tid] ?? []);
            }
            $rows[] = ['test' => $t, 'cells' => $cells];
        }

        $homa = null;
        $glic = $testsByCode['glicemia'] ?? null;
        $ins = $testsByCode['insulina'] ?? null;
        if ($glic !== null && $ins !== null && $glic['unit'] === 'mg/dL' && $ins['unit'] === 'µU/mL') {
            $cells = [];
            foreach ($collections as $c) {
                $g = $byTest[(int) $glic['id']][$c['id']]['value_num'] ?? null;
                $i = $byTest[(int) $ins['id']][$c['id']]['value_num'] ?? null;
                $h = LabClassifier::homaIr($g, $i);
                if ($h !== null) {
                    $cells[$c['id']] = ['value' => fmt_num($h, 2)];
                }
            }
            if ($cells !== []) {
                $homa = ['cells' => $cells];
            }
        }
        return ['collections' => $collections, 'total' => $total, 'rows' => $rows, 'homa' => $homa];
    }

    /** @return array{test:array,points:list<array>,band:?array} */
    public function series(int $patientId, int $testId): array
    {
        $test = $this->catalog->find($testId);
        if ($test === null) {
            throw new \RuntimeException('Exame não encontrado.');
        }
        $patient = $this->patients->find($patientId);
        $ranges = $this->catalog->ranges($testId);
        $rows = $this->db->all(
            'SELECT c.collected_on, r.value_num FROM lab_results r
             JOIN lab_collections c ON c.id = r.collection_id
             WHERE c.patient_id = ? AND c.deleted_at IS NULL AND r.test_id = ? AND r.value_num IS NOT NULL
             ORDER BY c.collected_on, c.id',
            [$patientId, $testId],
        );
        $points = [];
        $rangeIds = [];
        $firstRange = null;
        foreach ($rows as $row) {
            $value = (float) $row['value_num'];
            $range = $this->rangeFor($ranges, $patient, (string) $row['collected_on']);
            $rangeIds[$range === null ? 'none' : (string) $range['id']] = true;
            $firstRange ??= $range;
            $points[] = [
                'date' => (string) $row['collected_on'],
                'value' => $value,
                'class' => $range === null ? null : LabClassifier::classify($value, $range),
            ];
        }
        $band = null;
        if ($points !== [] && count($rangeIds) === 1 && $firstRange !== null) {
            $band = [
                'low' => $firstRange['borderline_low'] ?? $firstRange['abnormal_low'],
                'high' => $firstRange['borderline_high'] ?? $firstRange['abnormal_high'],
            ];
        }
        return ['test' => $test, 'points' => $points, 'band' => $band];
    }

    /** @return list<array{collected_on:string,items:list<array>}> */
    public function familyView(int $patientId): array
    {
        $patient = $this->patients->find($patientId);
        $ranges = $this->catalog->rangesByTest();
        $tests = [];
        foreach ($this->catalog->tests(false) as $t) {
            $tests[(int) $t['id']] = $t;
        }
        $out = [];
        foreach ($this->collections($patientId, true) as $c) {
            $byTest = [];
            foreach ($c['results'] as $res) {
                $byTest[$res['test_id']] = $res;
            }
            $items = [];
            foreach ($tests as $tid => $t) {
                if (!isset($byTest[$tid])) {
                    continue;
                }
                $cell = $this->cell($t, $byTest[$tid], $patient, (string) $c['collected_on'], $ranges[$tid] ?? []);
                $items[] = [
                    'name' => (string) $t['name'],
                    'value' => $cell['value'],
                    'unit' => (string) $t['unit'],
                    'phrase' => LabClassifier::familyPhrase($cell['class']),
                ];
            }
            $out[] = ['collected_on' => (string) $c['collected_on'], 'items' => $items];
        }
        return $out;
    }

    // ---- internos ----

    /** @return array{value:string,class:?string,no_range:bool} */
    private function cell(array $test, array $res, ?array $patient, string $on, array $ranges): array
    {
        if ($res['value_num'] === null) {
            return ['value' => (string) $res['value_text'], 'class' => null, 'no_range' => false];
        }
        $v = (float) $res['value_num'];
        $range = $this->rangeFor($ranges, $patient, $on);
        return [
            'value' => fmt_num($v, (int) $test['decimals']),
            'class' => $range === null ? null : LabClassifier::classify($v, $range),
            'no_range' => $range === null,
        ];
    }

    private function rangeFor(array $ranges, ?array $patient, string $on): ?array
    {
        if ($patient === null || $ranges === []) {
            return null;
        }
        return LabClassifier::pickRange(
            $ranges, (string) $patient['sex'],
            LabClassifier::ageMonths((string) $patient['birth_date'], $on),
        );
    }

    /**
     * @return array{0:array,1:array<int,array{num:?float,text:?string}>}
     */
    private function clean(array $patient, array $data, array $values): array
    {
        $errors = [];
        $on = trim((string) ($data['collected_on'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        if ($d === false || $d->format('Y-m-d') !== $on) {
            $errors[] = 'Data da coleta inválida.';
        } elseif ($on > date('Y-m-d')) {
            $errors[] = 'A data da coleta não pode ser futura.';
        } elseif ($on < (string) $patient['birth_date']) {
            $errors[] = 'A data da coleta não pode ser anterior ao nascimento.';
        }

        $results = [];
        foreach ($values as $key => $raw) {
            $tid = (int) $key;
            $raw = (string) $raw;
            if (trim($raw) === '') {
                continue;
            }
            $test = $this->catalog->find($tid);
            if ($test === null || (int) $test['active'] !== 1) {
                $errors[] = 'Exame inválido.';
                continue;
            }
            try {
                $parsed = LabValue::parse($raw);
            } catch (\InvalidArgumentException) {
                $errors[] = $test['name'] . ': valor inválido.';
                continue;
            }
            if ($parsed !== null && $parsed['num'] !== null
                && (!is_finite($parsed['num']) || abs($parsed['num']) > self::MAX_ABS)) {
                $errors[] = $test['name'] . ': valor inválido.';
                continue;
            }
            if ($parsed !== null) {
                $results[$tid] = $parsed;
            }
        }
        if ($errors === [] && $results === []) {
            $errors[] = 'Informe pelo menos um resultado.';
        }
        $errors = array_values(array_unique($errors));
        if ($errors !== []) {
            throw new InvalidLabException($errors);
        }
        $lab = trim((string) ($data['lab_name'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));
        return [
            ['collected_on' => $on, 'lab_name' => $lab === '' ? null : mb_substr($lab, 0, 120), 'notes' => $notes === '' ? null : $notes],
            $results,
        ];
    }

    /** @param array<int,array{num:?float,text:?string}> $results */
    private function insertResults(int $collectionId, array $results): void
    {
        foreach ($results as $tid => $p) {
            $this->db->insert('lab_results', [
                'collection_id' => $collectionId,
                'test_id' => $tid,
                'value_num' => $p['num'],
                'value_text' => $p['text'],
            ]);
        }
    }

    private function lock(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM lab_collections WHERE id = ? FOR UPDATE', [$id]);
        if ($row !== null) {
            $row['results'] = $this->resultsOf([$id])[$id] ?? [];
        }
        return $row;
    }

    private function change(int $id, int $uid, Request $r, string $action, string $sql): void
    {
        $this->db->transaction(function () use ($id, $uid, $r, $action, $sql): void {
            $before = $this->lock($id);
            if ($before === null || $before['deleted_at'] !== null) {
                throw new \RuntimeException('Coleta não encontrada.');
            }
            $this->db->run($sql, [$uid, $id]);
            $this->audit->record(
                $uid, $action, 'lab_collections', $id, (int) $before['patient_id'],
                $this->view($before), $this->view($this->find($id)), $r,
            );
        });
    }

    /** Estado completo (coleta + resultados) para a auditoria. */
    private function view(array $c): array
    {
        return [
            'collected_on' => $c['collected_on'],
            'lab_name' => $c['lab_name'],
            'notes' => $c['notes'],
            'released_at' => $c['released_at'],
            'deleted_at' => $c['deleted_at'],
            'results' => $c['results'],
        ];
    }

    /** @param list<array> $rows */
    private function withResults(array $rows, bool $withAttachments): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $results = $this->resultsOf($ids);
        $counts = [];
        if ($withAttachments) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->all(
                "SELECT collection_id, COUNT(*) AS n FROM lab_attachments WHERE deleted_at IS NULL AND collection_id IN ($in) GROUP BY collection_id",
                $ids,
            ) as $a) {
                $counts[(int) $a['collection_id']] = (int) $a['n'];
            }
        }
        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['results'] = $results[$id] ?? [];
            if ($withAttachments) {
                $row['attachment_count'] = $counts[$id] ?? 0;
            }
        }
        return $rows;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array{test_id:int,value_num:?float,value_text:?string}>>
     */
    private function resultsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->all(
            "SELECT collection_id, test_id, value_num, value_text FROM lab_results WHERE collection_id IN ($in) ORDER BY test_id",
            $ids,
        ) as $row) {
            $out[(int) $row['collection_id']][] = [
                'test_id' => (int) $row['test_id'],
                'value_num' => $row['value_num'] === null ? null : (float) $row['value_num'],
                'value_text' => $row['value_text'] === null ? null : (string) $row['value_text'],
            ];
        }
        return $out;
    }
}
