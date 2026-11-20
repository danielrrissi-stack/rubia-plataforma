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

/** Tratamento no portal: paciente adulto e responsavel. Nunca expoe notas, motivos, gravidade nem conduta. */
final class PortalTreatmentController
{
    private const FAMILY = ['paciente', 'responsavel'];
    private const STAFF = ['medico', 'nutricionista', 'secretaria'];
    private const ENDED_DAYS = 30;

    /** GET /portal/p/{id}/tratamento */
    public function show(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        Container::audit()->record((int) $user['id'], 'view', 'prescriptions', null, (int) $patient['id'], null, ['section' => 'medicamentos', 'portal' => true], $r);
        return $this->page($user, $patient, [], [], 200);
    }

    /** POST /portal/p/{id}/tratamento/{prescriptionId}/dose */
    public function dose(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $rxId = (int) $r->params['prescriptionId'];
        $rx = Container::prescriptions()->visibleTo($rxId, $user);
        if ($rx === null || (int) $rx['patient_id'] !== $pid) {
            throw new HttpException(404);
        }
        $data = $this->str($r->post['data'] ?? '');
        $hora = $this->str($r->post['hora'] ?? '');
        $note = $this->str($r->post['observacao'] ?? '');
        try {
            Container::medicationLogs()->record($rxId, trim($data . ' ' . $hora), $note, $user, date('Y-m-d H:i'), $r);
        } catch (InvalidMedicationException $e) {
            return $this->page($user, $patient, $e->errors(), ['rx' => $rxId, 'data' => $data, 'hora' => $hora, 'observacao' => $note], 422);
        }
        Session::flash('ok', 'Dose registrada.');
        return Response::redirect('/portal/p/' . $pid . '/tratamento');
    }

    /** POST /portal/p/{id}/tratamento/doses/{logId}/remover */
    public function remove(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $logId = (int) $r->params['logId'];
        $log = Container::db()->one('SELECT prescription_id FROM medication_logs WHERE id = ?', [$logId]);
        $rx = $log === null ? null : Container::prescriptions()->find((int) $log['prescription_id']);
        if ($rx === null || (int) $rx['patient_id'] !== $pid) {
            throw new HttpException(404);
        }
        try {
            Container::medicationLogs()->remove($logId, $user, date('Y-m-d H:i'), $r);
        } catch (InvalidMedicationException) {
            throw new HttpException(404);
        }
        Session::flash('ok', 'Dose removida.');
        return Response::redirect('/portal/p/' . $pid . '/tratamento');
    }

    /** GET /portal/p/{id}/tratamento/relato */
    public function reportForm(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        return $this->form($user, $patient, ['data' => date('Y-m-d'), 'descricao' => '', 'medicamento' => ''], [], 200);
    }

    /** POST /portal/p/{id}/tratamento/relato */
    public function report(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $values = [
            'data' => $this->str($r->post['data'] ?? ''),
            'descricao' => $this->str($r->post['descricao'] ?? ''),
            'medicamento' => $this->str($r->post['medicamento'] ?? ''),
        ];
        $rxId = (int) $values['medicamento'];
        if ($rxId !== 0) {
            $rx = Container::prescriptions()->visibleTo($rxId, $user);
            if ($rx === null || (int) $rx['patient_id'] !== $pid) {
                throw new HttpException(404);
            }
        }
        try {
            Container::adverseEvents()->report($pid, [
                'occurred_on' => $values['data'], 'description' => $values['descricao'], 'prescription_id' => $rxId,
            ], $user, date('Y-m-d'), $r);
        } catch (InvalidMedicationException $e) {
            return $this->form($user, $patient, $values, $e->errors(), 422);
        }
        Session::flash('ok', 'Relato enviado. A Dra. Rubia verá na próxima vez que abrir o sistema.');
        return Response::redirect('/portal/p/' . $pid . '/tratamento');
    }

    private function page(array $user, array $patient, array $errors, array $values, int $status): Response
    {
        $pid = (int) $patient['id'];
        $today = date('Y-m-d');
        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        $endedFrom = date('Y-m-d', strtotime('-' . self::ENDED_DAYS . ' days'));
        $items = [];
        foreach (Container::prescriptions()->forViewer($pid, $user, $today) as $p) {
            if ($p['status'] === 'encerrada' && (string) $p['end_date'] < $endedFrom) {
                continue;
            }
            $p['logs'] = $p['status'] === 'ativa' ? Container::medicationLogs()->forPrescription((int) $p['id'], $user, $since) : [];
            $items[] = $p;
        }
        return Response::html(View::render('Portal/views/treatment.php', [
            'title' => 'Tratamento', 'userName' => $user['name'], 'registerSw' => true,
            'patient' => $patient, 'first' => $this->firstName($patient), 'userId' => (int) $user['id'],
            'items' => $items, 'events' => Container::adverseEvents()->forViewer($pid, $user),
            'errors' => $errors, 'values' => $values, 'nowDate' => date('Y-m-d'), 'nowTime' => date('H:i'),
        ], 'Layout/views/layout_portal.php'), $status);
    }

    private function form(array $user, array $patient, array $values, array $errors, int $status): Response
    {
        $today = date('Y-m-d');
        $options = array_values(array_filter(
            Container::prescriptions()->forViewer((int) $patient['id'], $user, $today),
            static fn (array $p): bool => $p['status'] !== 'futura',
        ));
        return Response::html(View::render('Portal/views/event_form.php', [
            'title' => 'Contar um efeito', 'userName' => $user['name'], 'registerSw' => true,
            'patient' => $patient, 'first' => $this->firstName($patient),
            'values' => $values, 'errors' => $errors, 'prescriptions' => $options,
        ], 'Layout/views/layout_portal.php'), $status);
    }

    /** @return array{0:array,1:array,2:?Response} */
    private function open(Request $r): array
    {
        $user = $r->attributes['user'];
        $role = (string) ($user['role'] ?? '');
        if (in_array($role, self::STAFF, true)) {
            return [[], [], Response::redirect('/')];
        }
        if (!in_array($role, self::FAMILY, true)) {
            throw new HttpException(403, 'Acesso negado');
        }
        $id = (int) $r->params['id'];
        Container::gate()->authorize($user, 'view', Policy::MEDICAMENTOS, $id, $r);
        $patient = Container::patients()->find($id);
        if ($patient === null || $patient['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        return [$user, $patient, null];
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? mb_scrub($v, 'UTF-8') : '';
    }

    private function firstName(array $p): string
    {
        $name = trim((string) ($p['social_name'] ?: $p['full_name']));
        return explode(' ', $name)[0] ?? $name;
    }
}
