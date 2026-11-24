<?php
declare(strict_types=1);

namespace App\Labs;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;

/**
 * Catalogo de exames e faixas de referencia (pontos de corte por sexo e idade em meses; ver LabClassifier).
 * Faixas pre-carregadas ficam "a confirmar" (reference_confirmed = 0) ate a medica salvar ou confirmar.
 */
final class LabCatalogRepository
{
    private const CUTS = ['borderline_high', 'abnormal_high', 'borderline_low', 'abnormal_low'];

    public function __construct(private Database $db, private AuditLog $audit)
    {
    }

    /** @return list<array> ordenado por sort_order, nome */
    public function tests(bool $activeOnly = true): array
    {
        return $this->db->all(
            'SELECT * FROM lab_tests' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name, id',
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM lab_tests WHERE id = ?', [$id]);
    }

    public function findByCode(string $code): ?array
    {
        return $this->db->one('SELECT * FROM lab_tests WHERE code = ?', [$code]);
    }

    /** @return list<array> no formato de LabClassifier::pickRange (+ id, source) */
    public function ranges(int $testId): array
    {
        $rows = $this->db->all(
            'SELECT * FROM lab_ranges WHERE test_id = ? ORDER BY age_min_months, sex, id',
            [$testId],
        );
        return array_map(self::castRange(...), $rows);
    }

    /** @return array<int, list<array>> faixas de todos os exames, por test_id (exame sem faixa fica de fora) */
    public function rangesByTest(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM lab_ranges ORDER BY test_id, age_min_months, sex, id') as $row) {
            $out[(int) $row['test_id']][] = self::castRange($row);
        }
        return $out;
    }

    /** Cria ($id null) ou altera um exame. Exames novos nao tem `code` (o codigo e so dos pre-carregados). */
    public function saveTest(?int $id, array $data, int $uid, Request $r): int
    {
        $before = null;
        if ($id !== null) {
            $before = $this->find($id);
            if ($before === null) {
                throw new \RuntimeException('Exame não encontrado.');
            }
        }
        $row = $this->cleanTest($data, $id);
        return $this->db->transaction(function () use ($id, $row, $before, $uid, $r): int {
            if ($id === null) {
                $newId = $this->db->insert('lab_tests', $row + ['created_by' => $uid]);
                $this->audit->record($uid, 'create', 'lab_tests', $newId, null, null, $row, $r);
                return $newId;
            }
            $this->db->run(
                'UPDATE lab_tests SET name = ?, unit = ?, group_name = ?, sort_order = ?, decimals = ?, active = ?, updated_at = NOW() WHERE id = ?',
                [$row['name'], $row['unit'], $row['group_name'], $row['sort_order'], $row['decimals'], $row['active'], $id],
            );
            $this->audit->record($uid, 'update', 'lab_tests', $id, null, $this->testView($before), $row, $r);
            return $id;
        });
    }

    /**
     * Substitui todas as faixas do exame (numa transacao) e marca o exame como revisado.
     * @param list<array> $ranges
     */
    public function saveRanges(int $testId, array $ranges, int $uid, Request $r): void
    {
        $test = $this->find($testId);
        if ($test === null) {
            throw new \RuntimeException('Exame não encontrado.');
        }
        $clean = $this->cleanRanges((string) $test['name'], $ranges);
        $this->db->transaction(function () use ($testId, $test, $clean, $uid, $r): void {
            $before = $this->ranges($testId);
            $this->db->run('DELETE FROM lab_ranges WHERE test_id = ?', [$testId]);
            foreach ($clean as $range) {
                $this->db->insert('lab_ranges', ['test_id' => $testId] + $range);
            }
            $this->db->run('UPDATE lab_tests SET reference_confirmed = 1, updated_at = NOW() WHERE id = ?', [$testId]);
            $this->audit->record(
                $uid, 'update', 'lab_ranges', $testId, null,
                ['test' => $test['name'], 'reference_confirmed' => (int) $test['reference_confirmed'], 'ranges' => $before],
                ['test' => $test['name'], 'reference_confirmed' => 1, 'ranges' => $this->ranges($testId)],
                $r,
            );
        });
    }

    /** A medica revisou as faixas e as aceita como estao. */
    public function confirm(int $testId, int $uid, Request $r): void
    {
        $test = $this->find($testId);
        if ($test === null) {
            throw new \RuntimeException('Exame não encontrado.');
        }
        $this->db->transaction(function () use ($testId, $test, $uid, $r): void {
            $this->db->run('UPDATE lab_tests SET reference_confirmed = 1, updated_at = NOW() WHERE id = ?', [$testId]);
            $ranges = $this->ranges($testId);
            $this->audit->record(
                $uid, 'confirm', 'lab_tests', $testId, null,
                ['reference_confirmed' => (int) $test['reference_confirmed'], 'ranges' => $ranges],
                ['reference_confirmed' => 1, 'ranges' => $ranges],
                $r,
            );
        });
    }

    private static function castRange(array $row): array
    {
        $num = static fn(mixed $v): ?float => $v === null ? null : (float) $v;
        return [
            'id' => (int) $row['id'],
            'sex' => $row['sex'] === null || $row['sex'] === '' ? null : (string) $row['sex'],
            'age_min_months' => (int) $row['age_min_months'],
            'age_max_months' => $row['age_max_months'] === null ? null : (int) $row['age_max_months'],
            'borderline_high' => $num($row['borderline_high']),
            'abnormal_high' => $num($row['abnormal_high']),
            'borderline_low' => $num($row['borderline_low']),
            'abnormal_low' => $num($row['abnormal_low']),
            'source' => $row['source'] === null ? null : (string) $row['source'],
        ];
    }

    private function testView(array $row): array
    {
        return [
            'name' => $row['name'], 'unit' => $row['unit'], 'group_name' => $row['group_name'],
            'sort_order' => (int) $row['sort_order'], 'decimals' => (int) $row['decimals'], 'active' => (int) $row['active'],
        ];
    }

    private function cleanTest(array $d, ?int $id): array
    {
        $text = static fn(mixed $v): string => trim(mb_scrub((string) ($v ?? ''), 'UTF-8'));
        $name = $text($d['name'] ?? '');
        $unit = $text($d['unit'] ?? '');
        $group = $text($d['group_name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors[] = 'Informe o nome do exame.';
        } elseif (mb_strlen($name, 'UTF-8') > 100) {
            $errors[] = 'O nome do exame deve ter até 100 caracteres.';
        }
        if ($unit === '') {
            $errors[] = 'Informe a unidade do exame.';
        } elseif (mb_strlen($unit, 'UTF-8') > 20) {
            $errors[] = 'A unidade deve ter até 20 caracteres.';
        }
        if (mb_strlen($group, 'UTF-8') > 40) {
            $errors[] = 'O grupo deve ter até 40 caracteres.';
        }
        $sort = filter_var($d['sort_order'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => -32768, 'max_range' => 32767]]);
        if ($sort === false) {
            $errors[] = 'Ordem inválida.';
        }
        $decimals = filter_var($d['decimals'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4]]);
        if ($decimals === false) {
            $errors[] = 'Casas decimais devem ser de 0 a 4.';
        }
        $active = !empty($d['active'] ?? 1) ? 1 : 0;
        if ($name !== '' && $active === 1) {
            $dup = $this->db->one(
                'SELECT id FROM lab_tests WHERE active = 1 AND name = ? AND id <> ?',
                [$name, $id ?? 0],
            );
            if ($dup !== null) {
                $errors[] = 'Já existe um exame ativo chamado "' . $name . '".';
            }
        }
        if ($errors !== []) {
            throw new InvalidLabException($errors);
        }
        return [
            'name' => $name, 'unit' => $unit, 'group_name' => $group === '' ? 'outros' : $group,
            'sort_order' => (int) $sort, 'decimals' => (int) $decimals, 'active' => $active,
        ];
    }

    /** @param list<array> $ranges @return list<array> */
    private function cleanRanges(string $testName, array $ranges): array
    {
        $errors = [];
        $clean = [];
        foreach (array_values($ranges) as $i => $in) {
            $label = 'Faixa ' . ($i + 1) . ' de ' . $testName . ': ';
            $sex = $in['sex'] ?? null;
            $sex = $sex === '' ? null : $sex;
            if ($sex !== null && !in_array($sex, ['M', 'F'], true)) {
                $errors[] = $label . 'sexo inválido.';
                continue;
            }
            $min = self::intOrNull($in['age_min_months'] ?? null);
            $max = self::intOrNull($in['age_max_months'] ?? null);
            $maxGiven = !self::blank($in['age_max_months'] ?? null);
            if ($min === null || $min < 0 || $min > 65535 || ($maxGiven && ($max === null || $max < 0 || $max > 65535))) {
                $errors[] = $label . 'idade inválida (em meses, de 0 a 65535).';
                continue;
            }
            if ($max !== null && $min > $max) {
                $errors[] = $label . 'a idade inicial deve ser menor ou igual à final.';
                continue;
            }
            $cuts = [];
            $bad = false;
            foreach (self::CUTS as $k) {
                $v = $in[$k] ?? null;
                if (self::blank($v)) {
                    $cuts[$k] = null;
                } elseif (is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)))) {
                    $cuts[$k] = round((float) $v, 3);
                    if (abs($cuts[$k]) >= 10000000) {
                        $bad = true;
                    }
                } else {
                    $bad = true;
                }
            }
            if ($bad) {
                $errors[] = $label . 'ponto de corte inválido.';
                continue;
            }
            if (array_filter($cuts, static fn(?float $v): bool => $v !== null) === []) {
                $errors[] = $label . 'informe ao menos um ponto de corte.';
                continue;
            }
            if ($cuts['borderline_high'] !== null && $cuts['abnormal_high'] !== null && $cuts['borderline_high'] >= $cuts['abnormal_high']) {
                $errors[] = $label . 'o limítrofe alto deve ser menor que o alterado alto.';
            }
            if ($cuts['borderline_low'] !== null && $cuts['abnormal_low'] !== null && $cuts['borderline_low'] <= $cuts['abnormal_low']) {
                $errors[] = $label . 'o limítrofe baixo deve ser maior que o alterado baixo.';
            }
            $highs = array_filter([$cuts['borderline_high'], $cuts['abnormal_high']], static fn(?float $v): bool => $v !== null);
            $lows = array_filter([$cuts['borderline_low'], $cuts['abnormal_low']], static fn(?float $v): bool => $v !== null);
            if ($highs !== [] && $lows !== [] && min($highs) <= max($lows)) {
                $errors[] = $label . 'os cortes do lado alto devem ficar acima dos cortes do lado baixo.';
            }
            $source = trim(mb_scrub((string) ($in['source'] ?? ''), 'UTF-8'));
            if (mb_strlen($source, 'UTF-8') > 255) {
                $errors[] = $label . 'a fonte deve ter até 255 caracteres.';
            }
            $clean[] = [
                'sex' => $sex, 'age_min_months' => $min, 'age_max_months' => $max,
                'source' => $source === '' ? null : $source,
            ] + $cuts;
        }
        if ($errors === []) {
            $n = count($clean);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $clean[$i];
                    $b = $clean[$j];
                    $sexClash = $a['sex'] === null || $b['sex'] === null || $a['sex'] === $b['sex'];
                    $aMax = $a['age_max_months'] ?? PHP_INT_MAX;
                    $bMax = $b['age_max_months'] ?? PHP_INT_MAX;
                    if ($sexClash && $a['age_min_months'] <= $bMax && $b['age_min_months'] <= $aMax) {
                        $errors[] = 'Faixas ' . ($i + 1) . ' e ' . ($j + 1) . ' de ' . $testName
                            . ': as idades se sobrepõem para o mesmo sexo (faixa "ambos" não pode coincidir com faixa de um sexo).';
                    }
                }
            }
        }
        if ($errors !== []) {
            throw new InvalidLabException($errors);
        }
        return $clean;
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\s*\d+\s*$/', $v) === 1) {
            return (int) $v;
        }
        return null;
    }
}
