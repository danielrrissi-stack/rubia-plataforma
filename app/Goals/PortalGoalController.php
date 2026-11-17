<?php
declare(strict_types=1);

namespace App\Goals;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use DateTimeImmutable;

/** Area de metas do portal: responsavel, paciente adulto e crianca com conta propria. */
final class PortalGoalController
{
    private const ROLES = ['responsavel', 'paciente', 'crianca'];
    private const STAFF = ['medico', 'nutricionista', 'secretaria'];
    private const DAY_SHORT = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
    private const DAY_LONG = [
        1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira',
        5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo',
    ];

    public function show(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $today = date('Y-m-d');
        $thisWeek = GoalCalc::weekStart($today);
        // Estrelas, selos e semana saem so do que o repositorio entrega a este usuario (sigilo).
        $all = Container::goals()->allForViewer($pid, $user);
        $goals = $all['goals'];

        $earliest = $goals === [] ? $thisWeek : GoalCalc::weekStart(min(array_column($goals, 'start_date')));
        $week = $this->parseWeek((string) $r->input('semana', ''), $thisWeek, $earliest);

        $checked = [];
        foreach ($all['checks'] as $c) {
            $checked[$c['goal_id']][$c['day']] = true;
        }
        $kid = $user['role'] !== 'paciente'; // textos ludicos para crianca e responsaveis
        $rows = [];
        foreach ($goals as $g) {
            $gid = (int) $g['id'];
            if (GoalCalc::activeDaysInWeek($g, $week) === 0) {
                continue;
            }
            $status = GoalCalc::weekStatus($g, array_keys($checked[$gid] ?? []), $week);
            $rows[] = [
                'id' => $gid,
                'emoji' => (string) $g['emoji'],
                'text' => $kid ? (string) ($g['child_text'] ?? $g['family_text']) : (string) $g['family_text'],
                'days' => $this->days($g, $week, $checked[$gid] ?? [], $today),
                'count' => ((int) $g['times_per_week'] < 7 && $status['applicable']) ? $status['done'] . ' de ' . $status['target'] : null,
            ];
        }
        $first = $this->firstName($patient);
        $weekDate = new DateTimeImmutable($week);
        return Response::html(View::render('Goals/views/portal_goals.php', [
            'title' => $user['role'] === 'paciente' ? 'Suas metas' : 'Metas de ' . $first,
            'userName' => $user['name'],
            'registerSw' => true,
            'pid' => $pid,
            'greeting' => $user['role'] === 'paciente' ? 'Suas metas' : 'Oi, ' . $first . '!',
            'isChild' => $user['role'] === 'crianca',
            'stars' => GoalCalc::stars($goals, $all['checks']),
            'badges' => array_map(
                static fn (string $b): string => GoalCalc::BADGE_LABELS[$b],
                GoalCalc::badges($goals, $all['checks'], $today)
            ),
            'rows' => $rows,
            'week' => $week,
            'weekLabel' => $weekDate->format('d/m') . ' a ' . $weekDate->modify('+6 days')->format('d/m'),
            'prevWeek' => $week > $earliest ? $weekDate->modify('-7 days')->format('Y-m-d') : null,
            'nextWeek' => $week < $thisWeek ? $weekDate->modify('+7 days')->format('Y-m-d') : null,
            'cards' => [], // "Para você ler": preenchido na Task 6
            'many' => $user['role'] === 'responsavel' && count(Container::patientScope()->accessiblePatientIds($user) ?? []) > 1,
        ], 'Layout/views/layout_portal.php'));
    }

    public function mark(Request $r): Response
    {
        return $this->toggle($r, true);
    }

    public function unmark(Request $r): Response
    {
        return $this->toggle($r, false);
    }

    private function toggle(Request $r, bool $on): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $goalId = (int) $r->params['goalId'];
        $goal = Container::goals()->find($goalId);
        if ($goal === null || (int) $goal['patient_id'] !== $pid) {
            throw new HttpException(404);
        }
        $day = (string) $r->input('dia', '');
        $today = date('Y-m-d');
        $error = null;
        try {
            if ($on) {
                Container::goals()->check($goalId, $day, $user, $today, $r);
            } else {
                Container::goals()->uncheck($goalId, $day, $user, $today, $r);
            }
        } catch (InvalidGoalException $e) {
            $error = $e->errors[0] ?? 'Não foi possível salvar a marcação.';
        }
        if (str_contains((string) ($r->server['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            if ($error !== null) {
                return Response::json(['ok' => false, 'error' => $error], 422);
            }
            $all = Container::goals()->allForViewer($pid, $user);
            return Response::json(['ok' => true, 'checked' => $on, 'stars' => GoalCalc::stars($all['goals'], $all['checks'])]);
        }
        if ($error !== null) {
            Session::flash('erro', $error);
        }
        $week = (string) $r->input('semana', '');
        $back = '/portal/p/' . $pid . '/metas';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1) {
            $back .= '?semana=' . $week;
        }
        return Response::redirect($back);
    }

    /** @return list<array{ymd:string,short:string,label:string,checked:bool,enabled:bool}> */
    private function days(array $goal, string $week, array $checked, string $today): array
    {
        $out = [];
        $d = new DateTimeImmutable($week);
        for ($i = 0; $i < 7; $i++, $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $n = (int) $d->format('N');
            $out[] = [
                'ymd' => $ymd,
                'short' => self::DAY_SHORT[$n],
                'label' => self::DAY_LONG[$n] . ' ' . $d->format('d/m'),
                'checked' => isset($checked[$ymd]),
                'enabled' => GoalCalc::canMark($goal, $ymd, $today),
            ];
        }
        return $out;
    }

    private function parseWeek(string $raw, string $thisWeek, string $earliest): string
    {
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $raw) : false;
        if ($d === false || $d->format('Y-m-d') !== $raw) {
            return $thisWeek;
        }
        $ws = GoalCalc::weekStart($raw);
        if ($ws > $thisWeek) {
            return $thisWeek;
        }
        return $ws < $earliest ? $earliest : $ws;
    }

    /** @return array{0:array,1:array,2:?Response} usuario, paciente, redirecionamento */
    private function open(Request $r): array
    {
        $user = $r->attributes['user'];
        $role = (string) ($user['role'] ?? '');
        if (in_array($role, self::STAFF, true)) {
            return [[], [], Response::redirect('/')];
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new HttpException(403, 'Acesso negado');
        }
        $id = (int) $r->params['id'];
        Container::gate()->authorize($user, 'view', Policy::METAS, $id, $r);
        $patient = Container::patients()->find($id);
        if ($patient === null || $patient['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        return [$user, $patient, null];
    }

    private function firstName(array $p): string
    {
        $name = trim((string) ($p['social_name'] ?: $p['full_name']));
        return explode(' ', $name)[0] ?? $name;
    }
}
