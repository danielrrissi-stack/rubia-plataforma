<?php
declare(strict_types=1);

namespace App\WeightGoals;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Growth\AdultAnthropometry;
use App\Growth\Age;
use App\Support\Num;

/** Meta de peso: pagina com grafico, definir/trocar/encerrar, JSON do grafico e cartao da ficha. So equipe. */
final class WeightGoalController
{
    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        WeightGoalAccess::authorize($user, 'view', $id, $r);
        $patient = $this->patient($id);
        Container::audit()->record((int) $user['id'], 'view', 'patients', $id, $id, null, ['section' => 'meta_peso'], $r);
        return $this->render($r, $patient, [], ['tipo' => '', 'alvo' => '', 'prazo' => '', 'notas' => '']);
    }

    public function store(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        WeightGoalAccess::authorize($user, 'edit', $id, $r);
        $patient = $this->patient($id);
        $today = date('Y-m-d');
        $values = [
            'tipo' => (string) ($r->post['tipo'] ?? ''), 'alvo' => trim((string) ($r->post['alvo'] ?? '')),
            'prazo' => (string) ($r->post['prazo'] ?? ''), 'notas' => trim((string) ($r->post['notas'] ?? '')),
        ];
        $allowed = WeightGoalKind::allowed((string) $patient['birth_date'], $today);
        $kind = $values['tipo'] !== '' ? $values['tipo'] : (count($allowed) === 1 ? $allowed[0] : '');
        $start = in_array($kind, [WeightGoalKind::PESO, WeightGoalKind::IMC_Z], true)
            ? WeightGoalPoints::starting($kind, $patient, Container::measurements()->current($id))
            : null;
        try {
            Container::weightGoals()->create($id, [
                'kind' => $kind,
                'start_on' => $start['on'] ?? '',
                'start_value' => $start['value'] ?? null,
                'target_value' => $this->num($values['alvo']),
                'target_date' => $values['prazo'],
                'notes' => $values['notas'],
            ], $patient, $today, (int) $user['id'], $r);
        } catch (InvalidWeightGoalException $e) {
            return $this->render($r, $patient, $e->errors, $values);
        }
        Session::flash('ok', 'Meta de peso salva.');
        return Response::redirect('/pacientes/' . $id . '/meta-peso');
    }

    public function end(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        WeightGoalAccess::authorize($user, 'edit', $id, $r);
        $this->patient($id);
        $goal = Container::weightGoals()->active($id);
        if ($goal === null || (string) $goal['id'] !== (string) ($r->post['meta'] ?? '')) {
            Session::flash('erro', 'Esta meta já foi encerrada ou trocada.');
        } else {
            Container::weightGoals()->end((int) $goal['id'], (int) $user['id'], $r);
            Session::flash('ok', 'Meta de peso encerrada.');
        }
        return Response::redirect('/pacientes/' . $id . '/meta-peso');
    }

    /**
     * GET /pacientes/{id}/meta-peso.json — dados do grafico da meta ativa.
     * {kind, unit:"kg"|"z", points:[[ymd,value,source]], path:[[start_on,start],[target_date,target]], target,
     *  trend:null|[[ymd,v],[ymd,v]], status, statusLabel} ou {"goal":null}.
     */
    public function data(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        WeightGoalAccess::authorize($user, 'view', $id, $r);
        $patient = $this->patient($id);
        $goal = Container::weightGoals()->active($id);
        if ($goal === null) {
            return Response::json(['goal' => null]);
        }
        [$points, $progress] = self::evaluate($goal, $patient, date('Y-m-d'));
        $trend = null;
        if ($progress['trend'] !== null && $points !== []) {
            $t = $progress['trend'];
            $x0 = Age::days((string) $goal['start_on'], $points[0]['on']);
            $x1 = Age::days((string) $goal['start_on'], (string) $goal['target_date']);
            $trend = [[$points[0]['on'], round($t['a'] + $t['b'] * $x0, 3)], [(string) $goal['target_date'], round($t['a'] + $t['b'] * $x1, 3)]];
        }
        return Response::json([
            'kind' => $goal['kind'],
            'unit' => $goal['kind'] === WeightGoalKind::PESO ? 'kg' : 'z',
            'points' => array_map(static fn (array $p): array => [$p['on'], round($p['value'], 3), $p['source']], $points),
            'path' => [[(string) $goal['start_on'], (float) $goal['start_value']], [(string) $goal['target_date'], (float) $goal['target_value']]],
            'target' => (float) $goal['target_value'],
            'trend' => $trend,
            'status' => $progress['status'],
            'statusLabel' => WeightGoalProgress::LABELS[$progress['status']],
        ]);
    }

    /**
     * Cartao "Meta de peso" (ficha, pagina da meta e linha do tempo). null se o usuario nao pode ver.
     * @return ?array{goal:?array, progress:?array, points:list<array>, canEdit:bool, equivalentKg:?float, targetBmi:?float, patientId:int}
     */
    public static function card(array $user, int $patientId, string $today, ?array $measurements = null): ?array
    {
        if (!WeightGoalAccess::canView($user, $patientId)) {
            return null;
        }
        $patient = Container::patients()->find($patientId);
        $goal = Container::weightGoals()->active($patientId);
        $out = ['goal' => $goal, 'progress' => null, 'points' => [], 'canEdit' => WeightGoalAccess::canEdit($user, $patientId),
            'equivalentKg' => null, 'targetBmi' => null, 'patientId' => $patientId];
        if ($goal === null || $patient === null) {
            return $out;
        }
        $measurements ??= Container::measurements()->current($patientId);
        [$points, $progress] = self::evaluate($goal, $patient, $today, null, $measurements);
        $out['points'] = $points;
        $out['progress'] = $progress;
        $last = null;
        foreach (array_reverse($measurements) as $m) {
            if ($m['height_cm'] !== null && $m['superseded_at'] === null && $m['deleted_at'] === null) {
                $last = $m;
                break;
            }
        }
        if ($last !== null && $goal['kind'] === WeightGoalKind::IMC_Z) {
            // idade e altura (com a correcao deitado/em pe) da propria medicao, como no escore-z
            $on = substr((string) $last['measured_on'], 0, 10);
            $height = Container::growth()->correctHeight((float) $last['height_cm'], Age::days((string) $patient['birth_date'], $on), $last['position']);
            $bmi = Container::growth()->bmiForZ((string) $patient['sex'], (string) $patient['birth_date'], $on, (float) $goal['target_value']);
            $out['equivalentKg'] = $bmi === null ? null : $bmi * ($height / 100) ** 2;
        } elseif ($last !== null) {
            $out['targetBmi'] = AdultAnthropometry::bmi((float) $goal['target_value'], (float) $last['height_cm']);
        }
        return $out;
    }

    /** @return array{0:list<array>,1:array} pontos (desde a partida) e situacao */
    public static function evaluate(array $goal, array $patient, string $today, ?string $untilYmd = null, ?array $measurements = null): array
    {
        $measurements ??= Container::measurements()->current((int) $patient['id']);
        $points = WeightGoalPoints::build((string) $goal['kind'], $patient, $measurements, (string) $goal['start_on']);
        if ($untilYmd !== null) {
            $points = array_values(array_filter($points, static fn (array $p): bool => $p['on'] <= $untilYmd));
        }
        return [$points, WeightGoalProgress::compute($goal, $points, $untilYmd ?? $today)];
    }

    private function render(Request $r, array $patient, array $errors, array $values): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $patient['id'];
        $today = date('Y-m-d');
        $allowed = WeightGoalKind::allowed((string) $patient['birth_date'], $today);
        $current = Container::measurements()->current($id);
        $starts = [];
        foreach ($allowed as $k) {
            $starts[$k] = WeightGoalPoints::starting($k, $patient, $current);
        }
        $history = [];
        foreach (Container::weightGoals()->history($id) as $g) {
            if ($g['ended_at'] === null) {
                continue;
            }
            $until = substr((string) $g['ended_at'], 0, 10);
            $history[] = ['goal' => $g, 'progress' => self::evaluate($g, $patient, $today, $until, $current)[1]];
        }
        return Response::html(View::render('WeightGoals/views/index.php', [
            'patient' => $patient, 'errors' => $errors, 'values' => $values,
            'card' => self::card($user, $id, $today, $current), 'allowed' => $allowed, 'starts' => $starts,
            'zLimit' => in_array(WeightGoalKind::IMC_Z, $allowed, true) ? WeightGoalKind::zDeadlineLimit((string) $patient['birth_date']) : null,
            'history' => $history,
            'title' => 'Meta de peso', 'userName' => $user['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'));
    }

    private function num(string $raw): ?float
    {
        try {
            return Num::parse($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }
}
