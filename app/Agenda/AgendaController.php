<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Agenda da equipe: dia/semana de uma profissional, marcar, detalhes da consulta, situacao, cancelar e remarcar.
 * Ordem: papel (403 auditado) -> profissional pedida (canView, 403 auditado) -> resto. Consulta por id: 404 quando
 * quem pede nao alcanca a agenda dela, ou quando e nutricionista e o paciente nao esta liberado (spec Review Focus 2).
 * Quem marca/muda decide AgendaAccess; as regras de horario ficam no AppointmentRepository.
 */
final class AgendaController
{
    use AgendaPages;

    private const SEARCH_LIMIT = 20;

    /** GET /agenda?prof&data&vista=dia|semana */
    public function index(Request $r): Response
    {
        $user = $this->staff($r);
        $agendas = Container::agendaAccess()->agendasFor($user);
        $prof = $this->chosenProfessional($r, $user, $agendas);
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i');
        $date = $this->dateParam($r, 'data', $today);
        $view = (string) $r->input('vista', '') === 'semana' ? 'semana' : 'dia';
        $d = new \DateTimeImmutable($date);
        $days = $view === 'semana'
            ? array_map(fn (int $i): string => $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->modify('+' . $i . ' days')->format('Y-m-d'), range(0, 6))
            : [$date];
        $step = $view === 'semana' ? 7 : 1;
        $grid = [];
        $closures = [];
        if ($prof !== null) {
            $visible = [];
            foreach ($days as $day) {
                $grid[$day] = $this->decorate(Container::appointments()->dayView($prof, $day, $now), $user, $prof, $visible);
            }
            $closures = array_values(array_filter(
                Container::schedules()->exceptions($prof, $days[0], $days[count($days) - 1]),
                fn (array $x): bool => (string) $x['kind'] === 'fechado',
            ));
        }
        return $this->page('Agenda/views/' . ($view === 'semana' ? 'week' : 'day') . '.php', 'Agenda', $r, [
            'agendas' => $agendas, 'prof' => $prof, 'professional' => $this->professionalOf($agendas, $prof),
            'date' => $date, 'today' => $today, 'view' => $view, 'days' => $days, 'grid' => $grid, 'closures' => $closures,
            'prev' => $d->modify('-' . $step . ' days')->format('Y-m-d'), 'next' => $d->modify('+' . $step . ' days')->format('Y-m-d'),
        ]);
    }

    /** GET /agenda/marcar?prof&inicio&q&paciente */
    public function bookForm(Request $r): Response
    {
        $user = $this->staff($r);
        $agendas = Container::agendaAccess()->agendasFor($user);
        $prof = $this->chosenProfessional($r, $user, $agendas);
        $start = $this->startParam($r);
        if ($prof === null || $start === null) {
            Session::flash('erro', 'Escolha um horário livre na agenda.');
            return Response::redirect('/agenda' . ($prof === null ? '' : '?prof=' . $prof));
        }
        $patientId = (int) $r->input('paciente', 0);
        return $this->bookPage($r, $user, $agendas, $prof, $start, $patientId > 0 ? $patientId : null, null, null, []);
    }

    /** POST /agenda/marcar */
    public function book(Request $r): Response
    {
        $user = $this->staff($r);
        $agendas = Container::agendaAccess()->agendasFor($user);
        $prof = $this->chosenProfessional($r, $user, $agendas);
        if ($prof === null) {
            $this->deny($user, $r);
        }
        $start = (string) $r->input('inicio', '');
        $patientId = (int) $r->input('patient_id', 0);
        $kind = (string) $r->input('kind', '');
        $note = (string) $r->input('note', '');
        if ($patientId <= 0) {
            return $this->bookPage($r, $user, $agendas, $prof, $start, null, $kind, $note, ['Escolha o paciente.']);
        }
        try {
            Container::appointments()->book($prof, $patientId, $start, $kind, $note, $user, date('Y-m-d H:i'), $r);
        } catch (InvalidAgendaException $e) {
            return $this->bookPage($r, $user, $agendas, $prof, $start, $patientId, $kind, $note, $e->errors());
        }
        Session::flash('ok', 'Consulta marcada.');
        return Response::redirect('/agenda?prof=' . $prof . '&data=' . substr($start, 0, 10));
    }

    /** GET /pacientes/{id}/agendar?prof — proximos 10 horarios livres (60 dias) da profissional para o paciente. */
    public function patientSlots(Request $r): Response
    {
        $user = $this->staff($r);
        $pid = (int) $r->params['id'];
        $patient = Container::patients()->find($pid);
        if ($patient === null) {
            throw new HttpException(404);
        }
        if (!Container::patientScope()->canAccess($user, $pid)) {
            $this->deny($user, $r, $pid);
        }
        $agendas = Container::agendaAccess()->agendasFor($user);
        $prof = $this->chosenProfessional($r, $user, $agendas);
        $refusal = null;
        $slots = [];
        if ($prof !== null) {
            $refusal = Container::agendaAccess()->mayBookPatient($prof, $pid, $user);
            if ($refusal === null) {
                $slots = $this->nextFree($prof, date('Y-m-d'), date('Y-m-d H:i'), 10, 60);
            }
        }
        return $this->page('Agenda/views/patient_slots.php', 'Agendar', $r, [
            'patient' => $patient, 'agendas' => $agendas, 'prof' => $prof, 'professional' => $this->professionalOf($agendas, $prof),
            'refusal' => $refusal, 'slots' => $slots,
        ]);
    }

    /** GET /agenda/consultas/{apptId} */
    public function show(Request $r): Response
    {
        [$user, $a] = $this->openAppointment($r);
        $now = date('Y-m-d H:i');
        $today = substr($now, 0, 10);
        $status = (string) $a['status'];
        $day = substr((string) $a['starts_at'], 0, 10);
        $active = in_array($status, AgendaStatus::REMINDABLE, true);
        return $this->page('Agenda/views/appointment.php', 'Consulta', $r, [
            'a' => $a,
            'today' => $today,
            'canConfirm' => $status === 'agendada' && $now . ':00' < (string) $a['ends_at'],
            'canFinish' => $active && $day <= $today,
            'canUndo' => in_array($status, ['realizada', 'faltou'], true) && $day === $today,
            'canChange' => $active,
            'evolution' => (string) $user['role'] === 'medico' && $status === 'realizada',
            'unreleased' => !Container::agendaAccess()->patientVisibleOnAgenda((int) $a['professional_id'], (int) $a['patient_id']),
            'createdBy' => Container::users()->find((int) $a['created_by']),
        ]);
    }

    /** POST /agenda/consultas/{apptId}/situacao */
    public function status(Request $r): Response
    {
        [$user, $a] = $this->openAppointment($r);
        $to = (string) $r->input('situacao', '');
        try {
            if (!in_array($to, ['confirmada', 'realizada', 'faltou', 'agendada'], true)) {
                throw new InvalidAgendaException(['Esta mudança de situação não é permitida.']);
            }
            Container::appointments()->setStatus((int) $a['id'], $to, $user, date('Y-m-d H:i'), $r);
            Session::flash('ok', $to === 'agendada' ? 'Situação desfeita: a consulta voltou para "Agendada".' : 'Situação: ' . AgendaStatus::label($to) . '.');
        } catch (InvalidAgendaException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível mudar a situação.');
        }
        return Response::redirect('/agenda/consultas/' . (int) $a['id']);
    }

    /** POST /agenda/consultas/{apptId}/cancelar */
    public function cancel(Request $r): Response
    {
        [$user, $a] = $this->openAppointment($r);
        try {
            Container::appointments()->cancel((int) $a['id'], (string) $r->input('motivo', ''), $user, date('Y-m-d H:i'), $r);
            Session::flash('ok', 'Consulta cancelada. O horário ficou livre.');
        } catch (InvalidAgendaException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível cancelar.');
        }
        return Response::redirect('/agenda/consultas/' . (int) $a['id']);
    }

    /** GET /agenda/consultas/{apptId}/remarcar?prof&data */
    public function rescheduleForm(Request $r): Response
    {
        [$user, $a] = $this->openAppointment($r);
        return $this->reschedulePage($r, $user, $a, null, []);
    }

    /** POST /agenda/consultas/{apptId}/remarcar */
    public function reschedule(Request $r): Response
    {
        [$user, $a] = $this->openAppointment($r);
        $agendas = Container::agendaAccess()->agendasFor($user);
        $prof = $this->chosenProfessional($r, $user, $agendas);
        if ($prof === null) {
            $this->deny($user, $r);
        }
        $start = (string) $r->input('inicio', '');
        try {
            $newId = Container::appointments()->reschedule((int) $a['id'], $prof, $start, $user, date('Y-m-d H:i'), $r);
        } catch (InvalidAgendaException $e) {
            return $this->reschedulePage($r, $user, $a, $this->validDate(substr($start, 0, 10)), $e->errors());
        }
        Session::flash('ok', 'Consulta remarcada.');
        return Response::redirect('/agenda/consultas/' . $newId);
    }

    // ---- helpers ----

    /**
     * Consulta por id: papel -> existe -> agenda alcancavel (canManageExisting) -> nutricionista so com paciente
     * liberado. Qualquer falha depois do papel = 404 (nao revela a consulta).
     *
     * @return array{0:array,1:array}
     */
    private function openAppointment(Request $r): array
    {
        $user = $this->staff($r);
        $a = Container::appointments()->find((int) $r->params['apptId']);
        $access = Container::agendaAccess();
        if ($a === null || !$access->canManageExisting($user, (int) $a['professional_id'])) {
            throw new HttpException(404);
        }
        if ((string) $user['role'] === 'nutricionista' && !$access->patientVisibleOnAgenda((int) $a['professional_id'], (int) $a['patient_id'])) {
            throw new HttpException(404);
        }
        return [$user, $a];
    }

    /**
     * Acrescenta a cada linha: `hidden` (nutricionista vendo consulta de paciente sem liberacao: sem nome, link nem
     * WhatsApp) e `unreleased` (para a equipe que ve o nome: paciente sem liberacao na agenda da nutricionista).
     *
     * @param array<int,bool> $visible cache paciente => visivel na agenda
     */
    private function decorate(array $rows, array $user, int $prof, array &$visible): array
    {
        $isNutri = (string) $user['role'] === 'nutricionista';
        foreach ($rows as &$row) {
            $row['hidden'] = false;
            $row['unreleased'] = false;
            if ($row['appointment'] === null) {
                continue;
            }
            $pid = (int) $row['appointment']['patient_id'];
            $visible[$pid] ??= Container::agendaAccess()->patientVisibleOnAgenda($prof, $pid);
            $row['hidden'] = $isNutri && !$visible[$pid];
            $row['unreleased'] = !$isNutri && !$visible[$pid];
        }
        unset($row);
        return $rows;
    }

    /** @return list<array{date:string,start:string}> */
    private function nextFree(int $prof, string $today, string $now, int $limit, int $days): array
    {
        $out = [];
        $d = new \DateTimeImmutable($today);
        for ($i = 0; $i < $days && count($out) < $limit; $i++) {
            $ymd = $d->modify('+' . $i . ' days')->format('Y-m-d');
            foreach (Container::appointments()->dayView($prof, $ymd, $now) as $row) {
                if ($row['state'] === 'livre') {
                    $out[] = ['date' => $ymd, 'start' => $row['start']];
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /** Pacientes que quem marca alcanca e, na agenda de nutricionista, os liberados para ela. @return list<array> */
    private function searchPatients(array $user, int $prof, string $q): array
    {
        $ids = Container::patientScope()->accessiblePatientIds($user);
        $profUser = Container::users()->find($prof);
        if ($profUser !== null && (string) $profUser['role'] === 'nutricionista') {
            $released = Container::patientScope()->accessiblePatientIds($profUser) ?? [];
            $ids = $ids === null ? $released : array_values(array_intersect($ids, $released));
        }
        return Container::patients()->search($q, $ids, self::SEARCH_LIMIT, false);
    }

    private function bookPage(Request $r, array $user, array $agendas, int $prof, string $start, ?int $patientId, ?string $kind, ?string $note, array $errors): Response
    {
        $q = trim((string) $r->input('q', ''));
        $patient = null;
        $refusal = null;
        if ($patientId !== null) {
            $patient = Container::patientScope()->canAccess($user, $patientId) ? Container::patients()->find($patientId) : null;
            if ($patient === null) {
                $refusal = 'Paciente não encontrado.';
            } elseif ($errors === []) {
                $refusal = Container::agendaAccess()->mayBookPatient($prof, $patientId, $user);
            }
        }
        return $this->page('Agenda/views/book.php', 'Marcar consulta', $r, [
            'prof' => $prof, 'professional' => $this->professionalOf($agendas, $prof), 'start' => $start,
            'q' => $q, 'results' => $q === '' || $patient !== null ? [] : $this->searchPatients($user, $prof, $q),
            'patient' => $patient, 'refusal' => $refusal,
            'kind' => $kind ?? ($patient !== null ? Container::appointments()->suggestedKind($prof, (int) $patient['id']) : 'primeira'),
            'note' => $note ?? '', 'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    private function reschedulePage(Request $r, array $user, array $a, ?string $date, array $errors): Response
    {
        $agendas = Container::agendaAccess()->agendasFor($user);
        $prof = $this->chosenProfessional($r, $user, []) ?? (Container::agendaAccess()->canView($user, (int) $a['professional_id'])
            ? (int) $a['professional_id'] : ($agendas[0]['id'] ?? null));
        $today = date('Y-m-d');
        $apptDay = substr((string) $a['starts_at'], 0, 10);
        $date ??= $this->dateParam($r, 'data', $apptDay >= $today ? $apptDay : $today);
        $refusal = null;
        $slots = [];
        if ($prof !== null) {
            $refusal = Container::agendaAccess()->mayBookPatient($prof, (int) $a['patient_id'], $user);
            if ($refusal === null) {
                $slots = array_values(array_filter(
                    Container::appointments()->dayView($prof, $date, date('Y-m-d H:i')),
                    fn (array $row): bool => $row['state'] === 'livre',
                ));
            }
        }
        $d = new \DateTimeImmutable($date);
        return $this->page('Agenda/views/reschedule.php', 'Remarcar consulta', $r, [
            'a' => $a, 'agendas' => $agendas, 'prof' => $prof, 'date' => $date, 'today' => $today,
            'canChange' => in_array((string) $a['status'], AgendaStatus::REMINDABLE, true),
            'refusal' => $refusal, 'slots' => $slots, 'errors' => $errors,
            'prev' => $d->modify('-1 day')->format('Y-m-d'), 'next' => $d->modify('+1 day')->format('Y-m-d'),
        ], $errors === [] ? 200 : 422);
    }

    /** 'Y-m-d H:i' valido de ?inicio=, ou null. */
    private function startParam(Request $r): ?string
    {
        $raw = (string) $r->input('inicio', '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $raw);
        return $d !== false && $d->format('Y-m-d H:i') === $raw ? $raw : null;
    }

    private function validDate(string $ymd): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $d !== false && $d->format('Y-m-d') === $ymd ? $ymd : null;
    }

    /** @param list<array{id:int,name:string,role:string}> $agendas */
    private function professionalOf(array $agendas, ?int $prof): ?array
    {
        if ($prof === null) {
            return null;
        }
        foreach ($agendas as $a) {
            if ($a['id'] === $prof) {
                return $a;
            }
        }
        $u = Container::users()->find($prof);
        return $u === null ? null : ['id' => (int) $u['id'], 'name' => (string) $u['name'], 'role' => (string) $u['role']];
    }
}
