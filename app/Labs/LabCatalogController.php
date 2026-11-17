<?php
declare(strict_types=1);

namespace App\Labs;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Support\Num;

/** Catalogo de exames e faixas de referencia (so medico). Desativa em vez de apagar. */
final class LabCatalogController
{
    private const CUT_KEYS = ['bh' => 'borderline_high', 'ah' => 'abnormal_high', 'bl' => 'borderline_low', 'al' => 'abnormal_low'];

    /** GET /catalogo-exames */
    public function index(Request $r): Response
    {
        $this->auth($r, 'view');
        return $this->page('Labs/views/catalog_index.php', 'Catálogo de exames', $r, [
            'tests' => Container::labCatalog()->tests(false),
            'groups' => LabController::GROUPS,
        ]);
    }

    /** GET /catalogo-exames/novo */
    public function createForm(Request $r): Response
    {
        $this->auth($r, 'edit');
        return $this->renderForm($r, null, ['name' => '', 'unit' => '', 'group_name' => 'outros', 'sort_order' => '100', 'decimals' => '1', 'active' => 1], [self::emptyRow()], []);
    }

    /** POST /catalogo-exames/novo */
    public function create(Request $r): Response
    {
        $user = $this->auth($r, 'edit');
        [$values, $rows, $ranges, $errors] = $this->readPost($r->post);
        if ($errors === []) {
            try {
                $uid = (int) $user['id'];
                $id = Container::db()->transaction(function () use ($values, $ranges, $uid, $r): int {
                    $id = Container::labCatalog()->saveTest(null, $values, $uid, $r);
                    if ($ranges !== []) {
                        Container::labCatalog()->saveRanges($id, $ranges, $uid, $r);
                    }
                    return $id;
                });
                Session::flash('ok', 'Exame cadastrado.');
                return Response::redirect('/catalogo-exames/' . $id . '/editar');
            } catch (InvalidLabException $e) {
                $errors = $e->errors;
            }
        }
        return $this->renderForm($r, null, $values, $rows, $errors);
    }

    /** GET /catalogo-exames/{testId}/editar */
    public function editForm(Request $r): Response
    {
        $this->auth($r, 'edit');
        $test = $this->test($r);
        $rows = array_map(self::rowFromRange(...), Container::labCatalog()->ranges((int) $test['id']));
        $rows[] = self::emptyRow();
        return $this->renderForm($r, $test, self::valuesFrom($test), $rows, [], self::gapWarnings(Container::labCatalog()->ranges((int) $test['id'])));
    }

    /** POST /catalogo-exames/{testId}/editar */
    public function update(Request $r): Response
    {
        $user = $this->auth($r, 'edit');
        $test = $this->test($r);
        [$values, $rows, $ranges, $errors] = $this->readPost($r->post);
        if ($errors === []) {
            $uid = (int) $user['id'];
            try {
                $stored = Container::labCatalog()->ranges((int) $test['id']);
                $changed = self::normalise($ranges) !== self::normalise($stored);
                Container::db()->transaction(function () use ($test, $values, $ranges, $changed, $uid, $r): void {
                    Container::labCatalog()->saveTest((int) $test['id'], $values, $uid, $r);
                    if ($changed) { // so editar as faixas as confirma; salvar so o cadastro mantem "a confirmar"
                        Container::labCatalog()->saveRanges((int) $test['id'], $ranges, $uid, $r);
                    }
                });
                Session::flash('ok', $changed ? 'Exame e faixas salvos (faixas confirmadas).' : 'Exame salvo. As faixas não foram alteradas.');
                return Response::redirect('/catalogo-exames/' . $test['id'] . '/editar');
            } catch (InvalidLabException $e) {
                $errors = $e->errors;
            }
        }
        return $this->renderForm($r, $test, $values, $rows, $errors);
    }

    /** POST /catalogo-exames/{testId}/confirmar */
    public function confirm(Request $r): Response
    {
        $user = $this->auth($r, 'edit');
        $test = $this->test($r);
        if ((int) $test['reference_confirmed'] === 0) {
            Container::labCatalog()->confirm((int) $test['id'], (int) $user['id'], $r);
            Session::flash('ok', 'Faixas de referência confirmadas.');
        }
        return Response::redirect('/catalogo-exames/' . $test['id'] . '/editar');
    }

    private function auth(Request $r, string $action): array
    {
        $user = $r->attributes['user'];
        Clinician::assert($user, $r);
        Container::gate()->authorize($user, $action, Policy::EXAMES, null, $r);
        return $user;
    }

    private function test(Request $r): array
    {
        $test = Container::labCatalog()->find((int) $r->params['testId']);
        if ($test === null) {
            throw new HttpException(404, 'Exame não encontrado');
        }
        return $test;
    }

    /** Faixas em forma comparavel (tipos fixos, ordenadas), para saber se o formulario mudou alguma coisa. */
    private static function normalise(array $ranges): array
    {
        $out = [];
        foreach ($ranges as $g) {
            $cut = static fn(mixed $v): ?float => $v === null ? null : round((float) $v, 3);
            $sex = $g['sex'] ?? null;
            $src = trim((string) ($g['source'] ?? ''));
            $out[] = [
                'sex' => $sex === '' ? null : $sex,
                'min' => (int) $g['age_min_months'],
                'max' => ($g['age_max_months'] ?? null) === null ? null : (int) $g['age_max_months'],
                'bh' => $cut($g['borderline_high'] ?? null), 'ah' => $cut($g['abnormal_high'] ?? null),
                'bl' => $cut($g['borderline_low'] ?? null), 'al' => $cut($g['abnormal_low'] ?? null),
                'source' => $src === '' ? null : $src,
            ];
        }
        usort($out, static fn(array $a, array $b): int => [(string) $a['sex'], $a['min'], $a['max'] ?? PHP_INT_MAX] <=> [(string) $b['sex'], $b['min'], $b['max'] ?? PHP_INT_MAX]);
        return $out;
    }

    private static function ageText(int $months): string
    {
        $y = intdiv($months, 12);
        $m = $months % 12;
        $parts = [];
        if ($y > 0 || $m === 0) {
            $parts[] = $y . ($y === 1 ? ' ano' : ' anos');
        }
        if ($m > 0) {
            $parts[] = $m . ($m === 1 ? ' mês' : ' meses');
        }
        return implode(' e ', $parts);
    }

    /**
     * Avisos (nao bloqueiam) de idades sem faixa entre faixas do mesmo sexo, dentro do intervalo coberto.
     * A idade final conta inteira, entao a faixa seguinte deve comecar no mes seguinte.
     * @param list<array> $ranges
     * @return list<string>
     */
    public static function gapWarnings(array $ranges): array
    {
        $groups = [];
        $general = array_values(array_filter($ranges, static fn(array $g): bool => ($g['sex'] ?? null) === null));
        if ($general !== []) {
            $groups['ambos os sexos'] = $general;
        }
        foreach (['M' => 'sexo M', 'F' => 'sexo F'] as $sex => $label) {
            $own = array_filter($ranges, static fn(array $g): bool => ($g['sex'] ?? null) === $sex);
            if ($own !== []) {
                $groups[$label] = array_values(array_filter($ranges, static fn(array $g): bool => ($g['sex'] ?? null) === null || $g['sex'] === $sex));
            }
        }
        $out = [];
        foreach ($groups as $label => $list) {
            usort($list, static fn(array $a, array $b): int => $a['age_min_months'] <=> $b['age_min_months']);
            $coveredUntil = null; // PHP_INT_MAX = sem limite
            foreach ($list as $g) {
                $min = (int) $g['age_min_months'];
                $max = ($g['age_max_months'] ?? null) === null ? PHP_INT_MAX : (int) $g['age_max_months'];
                if ($coveredUntil !== null && $min > $coveredUntil + 1) {
                    $out[] = 'Atenção (' . $label . '): as idades de ' . self::ageText($coveredUntil + 1) . ' a '
                        . self::ageText($min - 1) . ' ficam sem faixa. A idade final conta inteira: a faixa seguinte deve começar no mês seguinte.';
                }
                $coveredUntil = $coveredUntil === null ? $max : max($coveredUntil, $max);
            }
        }
        return $out;
    }

    private static function emptyRow(): array
    {
        return ['sex' => '', 'min_a' => '', 'min_m' => '', 'max_a' => '', 'max_m' => '', 'bh' => '', 'ah' => '', 'bl' => '', 'al' => '', 'source' => ''];
    }

    private static function fmt(?float $v): string
    {
        return $v === null ? '' : str_replace('.', ',', (string) $v);
    }

    private static function rowFromRange(array $g): array
    {
        $min = (int) $g['age_min_months'];
        $max = $g['age_max_months'];
        return [
            'sex' => (string) ($g['sex'] ?? ''),
            'min_a' => (string) intdiv($min, 12), 'min_m' => (string) ($min % 12),
            'max_a' => $max === null ? '' : (string) intdiv((int) $max, 12),
            'max_m' => $max === null ? '' : (string) ((int) $max % 12),
            'bh' => self::fmt($g['borderline_high']), 'ah' => self::fmt($g['abnormal_high']),
            'bl' => self::fmt($g['borderline_low']), 'al' => self::fmt($g['abnormal_low']),
            'source' => (string) ($g['source'] ?? ''),
        ];
    }

    private static function valuesFrom(array $t): array
    {
        return [
            'name' => (string) $t['name'], 'unit' => (string) $t['unit'], 'group_name' => (string) $t['group_name'],
            'sort_order' => (string) $t['sort_order'], 'decimals' => (string) $t['decimals'], 'active' => (int) $t['active'],
        ];
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? trim(mb_scrub($v, 'UTF-8')) : '';
    }

    /** @return array{0: array, 1: list<array>, 2: list<array>, 3: list<string>} exame, linhas para reexibir, faixas, erros */
    private function readPost(array $post): array
    {
        $group = self::str($post['group_name'] ?? '');
        $values = [
            'name' => self::str($post['name'] ?? ''), 'unit' => self::str($post['unit'] ?? ''),
            'group_name' => isset(LabController::GROUPS[$group]) ? $group : 'outros',
            'sort_order' => self::str($post['sort_order'] ?? '0'), 'decimals' => self::str($post['decimals'] ?? '0'),
            'active' => ($post['active'] ?? '') === '1' ? 1 : 0, // sempre explicito: ausente = desativado
        ];
        $rows = [];
        $ranges = [];
        $errors = [];
        $in = is_array($post['faixa'] ?? null) ? $post['faixa'] : [];
        foreach ($in as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $row = self::emptyRow();
            foreach ($row as $k => $_) {
                $row[$k] = self::str($raw[$k] ?? '');
            }
            $blank = true;
            foreach ($row as $k => $v) {
                if ($k !== 'sex' && $v !== '') {
                    $blank = false;
                }
            }
            if ($blank) {
                continue; // linha vazia e ignorada
            }
            $rows[] = $row;
            $n = count($rows);
            $range = ['sex' => in_array($row['sex'], ['M', 'F'], true) ? $row['sex'] : null, 'source' => $row['source'] === '' ? null : $row['source']];
            $range['age_min_months'] = self::months($row['min_a'], $row['min_m'], $n, 'idade inicial', $errors) ?? 0;
            $range['age_max_months'] = self::months($row['max_a'], $row['max_m'], $n, 'idade final', $errors);
            foreach (self::CUT_KEYS as $k => $name) {
                try {
                    $range[$name] = Num::parse($row[$k]);
                } catch (\InvalidArgumentException) {
                    $errors[] = 'Faixa ' . $n . ': ponto de corte inválido (use números, por exemplo 130 ou 5,7).';
                    $range[$name] = null;
                }
            }
            $ranges[] = $range;
        }
        if ($rows === []) {
            $rows[] = self::emptyRow();
        }
        return [$values, $rows, $ranges, array_values(array_unique($errors))];
    }

    private static function months(string $years, string $months, int $n, string $label, array &$errors): ?int
    {
        if ($years === '' && $months === '') {
            return null;
        }
        $y = $years === '' ? 0 : filter_var($years, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 5000]]);
        $m = $months === '' ? 0 : filter_var($months, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 11]]);
        if ($y === false || $m === false) {
            $errors[] = 'Faixa ' . $n . ': ' . $label . ' inválida (anos inteiros e meses de 0 a 11).';
            return null;
        }
        return $y * 12 + $m;
    }

    private function renderForm(Request $r, ?array $test, array $values, array $rows, array $errors, array $warnings = []): Response
    {
        return $this->page('Labs/views/catalog_form.php', $test === null ? 'Novo exame' : (string) $test['name'], $r, [
            'test' => $test, 'values' => $values, 'rows' => $rows, 'errors' => $errors, 'warnings' => $warnings, 'groups' => LabController::GROUPS,
        ]);
    }

    private function page(string $template, string $title, Request $r, array $data): Response
    {
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $r->attributes['user']['name'], 'active' => '/catalogo-exames',
        ], 'Layout/views/layout.php'));
    }
}
