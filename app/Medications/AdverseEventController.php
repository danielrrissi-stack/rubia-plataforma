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
use App\Labs\Clinician;

/** Efeitos adversos na equipe (so medico). Ordem: papel -> 404 -> Gate. */
final class AdverseEventController
{
    /** GET /pacientes/{id}/efeitos/novo */
    public function createForm(Request $r): Response
    {
        $id = $this->authPatient($r);
        return $this->renderForm($r, $this->patient($id), [
            'occurred_on' => date('Y-m-d'), 'description' => '', 'prescription_id' => '', 'severity' => '', 'conduct' => '',
        ], []);
    }

    /** POST /pacientes/{id}/efeitos/novo */
    public function create(Request $r): Response
    {
        $id = $this->authPatient($r);
        $patient = $this->patient($id);
        $values = [];
        foreach (['occurred_on', 'description', 'prescription_id', 'severity', 'conduct'] as $f) {
            $v = $r->post[$f] ?? '';
            $values[$f] = is_string($v) ? mb_scrub($v, 'UTF-8') : '';
        }
        try {
            Container::adverseEvents()->report($id, $values, $r->attributes['user'], date('Y-m-d'), $r);
        } catch (InvalidMedicationException $e) {
            return $this->renderForm($r, $patient, $values, $e->errors());
        }
        Session::flash('ok', 'Efeito registrado.');
        return Response::redirect('/pacientes/' . $id . '/tratamento');
    }

    /** POST /efeitos/{eventId}/ciente */
    public function review(Request $r): Response
    {
        $user = $r->attributes['user'];
        Clinician::assert($user, $r, null, 'adverse_events', 'medicamentos');
        $ev = $this->db()->one('SELECT * FROM adverse_events WHERE id = ?', [(int) $r->params['eventId']]);
        if ($ev === null || $ev['deleted_at'] !== null || $ev['source'] !== 'familia' || $ev['reviewed_by'] !== null) {
            throw new HttpException(404);
        }
        $pid = (int) $ev['patient_id'];
        Container::gate()->authorize($user, 'edit', Policy::MEDICAMENTOS, $pid, $r);
        $str = static fn (mixed $v): string => is_string($v) ? mb_scrub($v, 'UTF-8') : '';
        try {
            Container::adverseEvents()->review(
                (int) $ev['id'], $str($r->post['severity'] ?? ''), $str($r->post['conduct'] ?? ''), (int) $user['id'], $r,
            );
            Session::flash('ok', 'Relato marcado como visto.');
        } catch (InvalidMedicationException $e) {
            Session::flash('erro', implode(' ', $e->errors()));
        }
        return Response::redirect('/pacientes/' . $pid . '/tratamento');
    }

    private function db(): \App\Core\Database
    {
        return Container::db();
    }

    private function authPatient(Request $r): int
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Clinician::assert($user, $r, $id, 'adverse_events', 'medicamentos');
        Container::gate()->authorize($user, 'edit', Policy::MEDICAMENTOS, $id, $r);
        $this->patient($id);
        return $id;
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }

    private function renderForm(Request $r, array $patient, array $values, array $errors): Response
    {
        $user = $r->attributes['user'];
        $today = date('Y-m-d');
        return Response::html(View::render('Medications/views/event_form.php', [
            'title' => 'Registrar efeito', 'userName' => $user['name'], 'active' => '/pacientes',
            'patient' => $patient, 'values' => $values, 'errors' => $errors,
            'prescriptions' => Container::prescriptions()->forViewer((int) $patient['id'], $user, $today),
        ], 'Layout/views/layout.php'), $errors === [] ? 200 : 422);
    }
}
