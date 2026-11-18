<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * "Meus horarios": semana-modelo, duracao, endereco e horarios extras (a propria profissional ou a medica
 * administradora) e fechamentos (tambem a secretaria). Ordem: papel -> profissional (canView) -> permissao da acao
 * (403 auditado). Validacao e auditoria no ScheduleRepository.
 */
final class ScheduleController
{
    use AgendaPages;

    private const BLANK_ROWS = 3;

    /** GET /agenda/horarios?prof */
    public function show(Request $r): Response
    {
        [$user, $prof] = $this->open($r, 'view');
        return $this->schedulePage($r, $user, $prof, [], null, null);
    }

    /** POST /agenda/horarios */
    public function save(Request $r): Response
    {
        [$user, $prof] = $this->open($r, 'week');
        $ranges = $this->postedRanges($r);
        $minutes = (string) $r->input('slot_minutes', '');
        $address = (string) $r->input('address', '');
        try {
            $m = filter_var($minutes, FILTER_VALIDATE_INT);
            Container::schedules()->saveWeek($prof, $ranges, $m === false ? 0 : $m, $address, (int) $user['id'], $r);
        } catch (InvalidAgendaException $e) {
            return $this->schedulePage($r, $user, $prof, $e->errors(), 'week', [
                'ranges' => $ranges, 'slot_minutes' => $minutes, 'address' => $address,
            ]);
        }
        Session::flash('ok', 'Horários salvos.');
        return $this->back($prof);
    }

    /** POST /agenda/horarios/padrao */
    public function applyDefault(Request $r): Response
    {
        [$user, $prof] = $this->open($r, 'week');
        try {
            Container::schedules()->applyClinicDefault($prof, (int) $user['id'], $r);
            Session::flash('ok', 'Horário padrão da clínica aplicado.');
        } catch (InvalidAgendaException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível aplicar o horário padrão.');
        }
        return $this->back($prof);
    }

    /** POST /agenda/horarios/fechamento */
    public function addClosure(Request $r): Response
    {
        [$user, $prof] = $this->open($r, 'closure');
        $v = [
            'start_date' => (string) $r->input('start_date', ''), 'end_date' => (string) $r->input('end_date', ''),
            'start_time' => (string) $r->input('start_time', ''), 'end_time' => (string) $r->input('end_time', ''),
            'reason' => (string) $r->input('reason', ''),
        ];
        try {
            Container::schedules()->addClosure($prof, $v['start_date'], $v['end_date'], $v['start_time'], $v['end_time'], $v['reason'], (int) $user['id'], $r, date('Y-m-d'));
        } catch (InvalidAgendaException $e) {
            return $this->schedulePage($r, $user, $prof, $e->errors(), 'closure', $v);
        }
        $n = Container::schedules()->bookedInClosure($prof, $v['start_date'], $v['end_date'], $v['start_time'], $v['end_time']);
        Session::flash('ok', 'Fechamento lançado.' . match (true) {
            $n === 0 => '',
            $n === 1 => ' Há 1 consulta marcada nesse período. Ela continua na agenda para você remarcar.',
            default => ' Há ' . $n . ' consultas marcadas nesse período. Elas continuam na agenda para você remarcar.',
        });
        return $this->back($prof);
    }

    /** POST /agenda/horarios/extra */
    public function addExtra(Request $r): Response
    {
        [$user, $prof] = $this->open($r, 'extra');
        $v = [
            'date' => (string) $r->input('date', ''),
            'start_time' => (string) $r->input('start_time', ''), 'end_time' => (string) $r->input('end_time', ''),
        ];
        try {
            Container::schedules()->addExtra($prof, $v['date'], $v['start_time'], $v['end_time'], (int) $user['id'], $r, date('Y-m-d'));
        } catch (InvalidAgendaException $e) {
            return $this->schedulePage($r, $user, $prof, $e->errors(), 'extra', $v);
        }
        Session::flash('ok', 'Horário extra lançado.');
        return $this->back($prof);
    }

    /** POST /agenda/horarios/excecao/{exceptionId}/remover */
    public function removeException(Request $r): Response
    {
        $user = $this->staff($r);
        $ex = Container::schedules()->findException((int) $r->params['exceptionId']);
        if ($ex === null) {
            throw new HttpException(404);
        }
        $prof = (int) $ex['professional_id'];
        $access = Container::agendaAccess();
        $allowed = (string) $ex['kind'] === 'fechado' ? $access->canAddClosure($user, $prof) : $access->canAddExtra($user, $prof);
        if (!$allowed) {
            $this->deny($user, $r);
        }
        try {
            Container::schedules()->removeException((int) $ex['id'], (int) $user['id'], $r);
            Session::flash('ok', (string) $ex['kind'] === 'fechado' ? 'Fechamento removido.' : 'Horário extra removido.');
        } catch (InvalidAgendaException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível remover.');
        }
        return $this->back($prof);
    }

    // ---- helpers ----

    /**
     * Papel -> profissional (canView, 403) -> acao: 'view'/'closure' = canAddClosure, 'week' = canEditWeek,
     * 'extra' = canAddExtra.
     *
     * @return array{0:array,1:int}
     */
    private function open(Request $r, string $action): array
    {
        $user = $this->staff($r);
        $prof = $this->chosenProfessional($r, $user, Container::agendaAccess()->agendasFor($user));
        $access = Container::agendaAccess();
        $allowed = $prof !== null && match ($action) {
            'week' => $access->canEditWeek($user, $prof),
            'extra' => $access->canAddExtra($user, $prof),
            default => $access->canAddClosure($user, $prof),
        };
        if (!$allowed) {
            $this->deny($user, $r);
        }
        return [$user, $prof];
    }

    /** Linhas da semana-modelo do formulario; linhas sem horario nenhum sao ignoradas. @return list<array> */
    private function postedRanges(Request $r): array
    {
        $raw = $r->input('ranges', []);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $x = [
                'weekday' => (string) ($row['weekday'] ?? ''),
                'start_time' => trim((string) ($row['start_time'] ?? '')),
                'end_time' => trim((string) ($row['end_time'] ?? '')),
            ];
            if ($x['start_time'] === '' && $x['end_time'] === '') {
                continue;
            }
            $out[] = $x;
        }
        return $out;
    }

    /**
     * @param list<string> $errors
     * @param ?string $form formulario com erro ('week', 'closure', 'extra')
     */
    private function schedulePage(Request $r, array $user, int $prof, array $errors, ?string $form, ?array $values): Response
    {
        $access = Container::agendaAccess();
        $agendas = $this->activeAgendas($access->agendasFor($user));
        $repo = Container::schedules();
        $today = date('Y-m-d');
        $profile = $repo->profile($prof);
        $ranges = $form === 'week' ? $values['ranges'] : array_map(fn (array $x): array => [
            'weekday' => (string) $x['weekday'],
            'start_time' => substr((string) $x['start_time'], 0, 5),
            'end_time' => substr((string) $x['end_time'], 0, 5),
        ], $repo->ranges($prof));
        $hasRanges = $repo->ranges($prof) !== [];
        for ($i = 0; $i < self::BLANK_ROWS; $i++) {
            $ranges[] = ['weekday' => '', 'start_time' => '', 'end_time' => ''];
        }
        $professional = null;
        foreach ($agendas as $a) {
            if ($a['id'] === $prof) {
                $professional = $a;
            }
        }
        $label = $this->scheduleLabel($user, $prof, $professional);
        return $this->page('Agenda/views/schedule.php', $label, $r, [
            'agendas' => $agendas, 'prof' => $prof, 'professional' => $professional, 'label' => $label,
            'canEditWeek' => $access->canEditWeek($user, $prof), 'canAddExtra' => $access->canAddExtra($user, $prof),
            'hasRanges' => $hasRanges,
            'ranges' => $ranges,
            'slotMinutes' => $form === 'week' ? $values['slot_minutes'] : (string) $profile['slot_minutes'],
            'address' => $form === 'week' ? $values['address'] : (string) ($profile['address'] ?? ''),
            'exceptions' => $repo->exceptions($prof, $today, (new \DateTimeImmutable($today))->modify('+12 months')->format('Y-m-d')),
            'errors' => $errors, 'form' => $form, 'values' => $form === 'week' ? [] : ($values ?? []),
        ], $errors === [] ? 200 : 422);
    }

    private function back(int $prof): Response
    {
        return Response::redirect('/agenda/horarios?prof=' . $prof);
    }
}
