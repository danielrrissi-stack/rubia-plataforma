<?php
declare(strict_types=1);

namespace App\Medications;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Growth\Age;
use App\Labs\Clinician;

/**
 * Telas da equipe para prescricoes e historico de doses (so medico). Dose e frequencia sao texto livre:
 * nenhuma tela compara, soma ou sugere doses. Ordem: papel -> 404 -> Gate.
 */
final class PrescriptionController
{
    private const FIELDS = ['drug_name', 'route', 'start_date', 'end_date', 'next_review_date', 'dose', 'frequency', 'instructions', 'notes'];

    /** GET /pacientes/{id}/tratamento */
    public function index(Request $r): Response
    {
        $id = $this->authPatient($r, 'view');
        $user = $r->attributes['user'];
        $today = date('Y-m-d');
        Container::audit()->record((int) $user['id'], 'view', 'prescriptions', null, $id, null, ['section' => 'medicamentos'], $r);
        $current = [];
        $ended = [];
        foreach (Container::prescriptions()->forViewer($id, $user, $today) as $p) {
            if ($p['status'] === 'encerrada') {
                $ended[] = $p;
            } else {
                $current[] = $p;
            }
        }
        return $this->page('Medications/views/index.php', 'Tratamento', $r, [
            'patient' => $this->patient($id),
            'today' => $today,
            'current' => $current,
            'ended' => $ended,
            'events' => Container::adverseEvents()->forViewer($id, $user),
        ]);
    }

    /** GET /pacientes/{id}/tratamento/nova */
    public function createForm(Request $r): Response
    {
        $id = $this->authPatient($r, 'edit');
        return $this->renderForm($r, $this->patient($id), null, [
            'drug_name' => '', 'route' => 'oral', 'start_date' => date('Y-m-d'), 'end_date' => '', 'next_review_date' => '',
            'dose' => '', 'frequency' => '', 'instructions' => '', 'notes' => '', 'family_logs' => 1, 'secret' => 0,
        ], []);
    }

    /** POST /pacientes/{id}/tratamento/nova */
    public function create(Request $r): Response
    {
        $id = $this->authPatient($r, 'edit');
        $patient = $this->patient($id);
        $values = $this->posted($r);
        try {
            $rid = Container::prescriptions()->create($id, $values, $r->attributes['user'], date('Y-m-d'), $r);
        } catch (InvalidMedicationException $e) {
            return $this->renderForm($r, $patient, null, $values, $e->errors());
        }
        Session::flash('ok', 'Prescrição registrada.');
        return Response::redirect('/prescricoes/' . $rid);
    }

    /** GET /prescricoes/{prescriptionId} */
    public function show(Request $r): Response
    {
        $p = $this->authRx($r, 'view');
        $user = $r->attributes['user'];
        $pid = (int) $p['patient_id'];
        $rid = (int) $p['id'];
        $today = date('Y-m-d');
        Container::audit()->record((int) $user['id'], 'view', 'prescriptions', $rid, $pid, null, ['section' => 'medicamentos'], $r);
        $since = date('Y-m-d', strtotime($today . ' -90 days')) . ' 00:00:00';
        $events = array_values(array_filter(
            Container::adverseEvents()->forViewer($pid, $user),
            static fn (array $e): bool => (int) ($e['prescription_id'] ?? 0) === $rid,
        ));
        return $this->page('Medications/views/show.php', 'Prescrição', $r, [
            'patient' => $this->patient($pid),
            'p' => $p,
            'status' => Container::prescriptions()->status($p, $today),
            'today' => $today,
            'doses' => Container::prescriptions()->doses($rid),
            'logs' => Container::medicationLogs()->forPrescription($rid, $user, $since),
            'events' => $events,
        ]);
    }

    /** GET /prescricoes/{prescriptionId}/editar */
    public function editForm(Request $r): Response
    {
        $p = $this->authRx($r, 'edit');
        return $this->renderForm($r, $this->patient((int) $p['patient_id']), $p, [
            'drug_name' => (string) $p['drug_name'], 'route' => (string) $p['route'], 'start_date' => (string) $p['start_date'],
            'end_date' => (string) ($p['end_date'] ?? ''), 'next_review_date' => (string) ($p['next_review_date'] ?? ''),
            'notes' => (string) ($p['notes'] ?? ''), 'family_logs' => (int) $p['family_logs'], 'secret' => (int) $p['secret'],
        ], []);
    }

    /** POST /prescricoes/{prescriptionId}/editar — o formulario sempre posta o sigilo atual (update() o recalcula a cada gravacao). */
    public function update(Request $r): Response
    {
        $p = $this->authRx($r, 'edit');
        $values = $this->posted($r);
        try {
            Container::prescriptions()->update((int) $p['id'], $values, $r->attributes['user'], date('Y-m-d'), $r);
        } catch (InvalidMedicationException $e) {
            return $this->renderForm($r, $this->patient((int) $p['patient_id']), $p, $values, $e->errors());
        }
        Session::flash('ok', 'Prescrição atualizada.');
        return Response::redirect('/prescricoes/' . $p['id']);
    }

    /** GET /prescricoes/{prescriptionId}/dose */
    public function doseForm(Request $r): Response
    {
        $p = $this->authRx($r, 'edit');
        $today = date('Y-m-d');
        if (Container::prescriptions()->status($p, $today) === 'encerrada') {
            Session::flash('erro', 'A prescrição está encerrada.');
            return Response::redirect('/prescricoes/' . $p['id']);
        }
        $cur = Container::prescriptions()->doseOn((int) $p['id'], $today) ?? (Container::prescriptions()->doses((int) $p['id'])[0] ?? null);
        return $this->renderDose($r, $p, [
            'effective_date' => max($today, (string) $p['start_date']),
            'dose' => (string) ($cur['dose'] ?? ''), 'frequency' => (string) ($cur['frequency'] ?? ''),
            'instructions' => (string) ($cur['instructions'] ?? ''), 'reason' => '',
        ], []);
    }

    /** POST /prescricoes/{prescriptionId}/dose */
    public function changeDose(Request $r): Response
    {
        $p = $this->authRx($r, 'edit');
        $values = $this->posted($r);
        try {
            Container::prescriptions()->changeDose((int) $p['id'], $values, (int) $r->attributes['user']['id'], date('Y-m-d'), $r);
        } catch (InvalidMedicationException $e) {
            return $this->renderDose($r, $p, $values, $e->errors());
        }
        Session::flash('ok', 'Nova dose registrada.');
        return Response::redirect('/prescricoes/' . $p['id']);
    }

    /** POST /prescricoes/{prescriptionId}/encerrar */
    public function end(Request $r): Response
    {
        $p = $this->authRx($r, 'edit');
        $date = trim($this->str($r->post['end_date'] ?? ''));
        try {
            Container::prescriptions()->end(
                (int) $p['id'], $date === '' ? date('Y-m-d') : $date, trim($this->str($r->post['reason'] ?? '')) ?: null,
                (int) $r->attributes['user']['id'], date('Y-m-d'), $r,
            );
        } catch (InvalidMedicationException $e) {
            Session::flash('erro', implode(' ', $e->errors()));
            return Response::redirect('/prescricoes/' . $p['id']);
        }
        Session::flash('ok', 'Prescrição encerrada.');
        return Response::redirect('/prescricoes/' . $p['id']);
    }

    /** POST /prescricoes/{prescriptionId}/excluir */
    public function delete(Request $r): Response
    {
        $p = $this->authRx($r, 'edit');
        try {
            Container::prescriptions()->delete((int) $p['id'], (int) $r->attributes['user']['id'], $r);
        } catch (InvalidMedicationException) {
            throw new HttpException(404);
        }
        Session::flash('ok', 'Prescrição excluída.');
        return Response::redirect('/pacientes/' . $p['patient_id'] . '/tratamento');
    }

    /** Cartao "Tratamento" da ficha; so medico. @return ?array{items:list<array>,review:?string,pending:int} */
    public static function card(array $user, int $patientId, string $today): ?array
    {
        if (!Clinician::is($user) || !Container::gate()->check($user, 'view', Policy::MEDICAMENTOS, $patientId)) {
            return null;
        }
        $active = array_values(array_filter(
            Container::prescriptions()->forViewer($patientId, $user, $today),
            static fn (array $p): bool => $p['status'] === 'ativa',
        ));
        $reviews = array_filter(array_column($active, 'next_review_date'));
        $pending = count(array_filter(
            Container::adverseEvents()->forViewer($patientId, $user),
            static fn (array $e): bool => $e['source'] === 'familia' && $e['reviewed_by'] === null,
        ));
        return [
            'items' => array_slice($active, 0, 5),
            'review' => $reviews === [] ? null : min($reviews),
            'pending' => $pending,
        ];
    }

    // ---- internos ----

    private function str(mixed $v): string
    {
        return is_string($v) ? mb_scrub($v, 'UTF-8') : '';
    }

    /** @return array<string,mixed> */
    private function posted(Request $r): array
    {
        $out = [];
        foreach ([...self::FIELDS, 'effective_date', 'reason'] as $f) {
            $out[$f] = $this->str($r->post[$f] ?? '');
        }
        $out['family_logs'] = !empty($r->post['family_logs']) ? 1 : 0;
        $out['secret'] = !empty($r->post['secret']) ? 1 : 0;
        return $out;
    }

    private function authPatient(Request $r, string $action): int
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Clinician::assert($user, $r, $id, 'prescriptions', 'medicamentos');
        Container::gate()->authorize($user, $action, Policy::MEDICAMENTOS, $id, $r);
        $this->patient($id);
        return $id;
    }

    /** Papel -> 404 (inexistente ou excluida) -> Gate. */
    private function authRx(Request $r, string $action): array
    {
        $user = $r->attributes['user'];
        Clinician::assert($user, $r, null, 'prescriptions', 'medicamentos');
        $p = Container::prescriptions()->find((int) $r->params['prescriptionId']);
        if ($p === null || $p['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        Container::gate()->authorize($user, $action, Policy::MEDICAMENTOS, (int) $p['patient_id'], $r);
        return $p;
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }

    private function renderForm(Request $r, array $patient, ?array $rx, array $values, array $errors): Response
    {
        return $this->page('Medications/views/form.php', $rx === null ? 'Nova prescrição' : 'Editar prescrição', $r, [
            'patient' => $patient,
            'rx' => $rx,
            'values' => $values,
            'errors' => $errors,
            'canSecret' => Age::years((string) $patient['birth_date'], date('Y-m-d')) < 18,
            'drugNames' => Container::prescriptions()->drugNames(),
        ], $errors === [] ? 200 : 422);
    }

    private function renderDose(Request $r, array $rx, array $values, array $errors): Response
    {
        return $this->page('Medications/views/dose_form.php', 'Alterar dose', $r, [
            'patient' => $this->patient((int) $rx['patient_id']),
            'rx' => $rx,
            'values' => $values,
            'errors' => $errors,
        ], $errors === [] ? 200 : 422);
    }

    private function page(string $template, string $title, Request $r, array $data, int $status = 200): Response
    {
        $user = $r->attributes['user'];
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $user['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'), $status);
    }
}
