<?php
declare(strict_types=1);

namespace App\Certificates;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Labs\Clinician;
use App\Medications\PrescriptionPrintController;
use App\Pdf\PdfFilename;

/** Atestados e declaracoes: so a medica emite, baixa e anula; nada e apagado. */
final class CertificateController
{
    /** Cartao da ficha do paciente (null quando o usuario nao pode ver). */
    public static function card(array $user, int $patientId): ?array
    {
        if (!self::can($user, $patientId)) {
            return null;
        }
        return ['items' => Container::certificates()->forPatient($patientId, 5), 'patientId' => $patientId];
    }

    public static function can(array $user, int $patientId): bool
    {
        return Clinician::is($user) && Container::gate()->check($user, 'view', Policy::CLINICO, $patientId);
    }

    /** GET /pacientes/{id}/atestados */
    public function index(Request $r): Response
    {
        [$user, $patient] = $this->auth($r, 'view');
        $pid = (int) $patient['id'];
        Container::audit()->record((int) $user['id'], 'view', 'certificates', null, $pid, null, ['section' => 'atestados', 'lista' => true], $r);
        return $this->page('index.php', 'Atestados', $user, [
            'patient' => $patient, 'items' => Container::certificates()->forPatient($pid),
        ]);
    }

    /** GET /pacientes/{id}/atestados/novo?tipo= */
    public function create(Request $r): Response
    {
        [$user, $patient] = $this->auth($r, 'edit');
        $type = is_string($r->query['tipo'] ?? null) ? $r->query['tipo'] : '';
        $values = ['data' => date('Y-m-d'), 'inicio' => '', 'fim' => '', 'dias' => '', 'a_partir' => '', 'acompanhante_nome' => '',
            'acompanhante_cpf' => '', 'cid' => '', 'cid_autorizado' => '', 'observacao' => ''];
        if ($type === 'acompanhante') {
            $g = Container::guardians()->forPatient((int) $patient['id']);
            $values['acompanhante_nome'] = (string) ($g[0]['user_name'] ?? '');
        }
        return $this->form($user, $patient, CertificateType::valid($type) ? $type : null, $values, []);
    }

    /** POST /pacientes/{id}/atestados */
    public function store(Request $r): Response
    {
        [$user, $patient] = $this->auth($r, 'edit');
        $pid = (int) $patient['id'];
        $type = (string) $r->input('tipo', '');
        $in = [];
        foreach (['data', 'inicio', 'fim', 'dias', 'a_partir', 'acompanhante_nome', 'acompanhante_cpf', 'cid', 'cid_autorizado', 'observacao'] as $k) {
            $v = $r->input($k, '');
            $in[$k] = is_string($v) ? $v : '';
        }
        $today = date('Y-m-d');
        [$clean, $errors] = CertificateText::validate($type, $in, $today);
        if ($errors !== []) {
            return $this->form($user, $patient, CertificateType::valid($type) ? $type : null, $in, $errors, 422);
        }
        $doctor = Container::db()->one('SELECT name, specialty, professional_register, rqe FROM users WHERE id = ?', [(int) $user['id']]) ?? $user;
        $html = CertificateText::document($type, $clean, $patient, $doctor, PrescriptionPrintController::headSettings(), $today);
        $id = Container::certificates()->create($pid, (int) $user['id'], $type, $today, $clean, $html, $clean['cid']);
        Container::audit()->record((int) $user['id'], 'create', 'certificates', $id, $pid, null,
            ['section' => 'atestados', 'tipo' => $type, 'emitido_em' => $today, 'cid' => $clean['cid'] !== null], $r);
        Session::flash('ok', 'Atestado emitido.');
        return Response::redirect('/pacientes/' . $pid . '/atestados/' . $id);
    }

    /** GET /pacientes/{id}/atestados/{certId} */
    public function show(Request $r): Response
    {
        [$user, $patient] = $this->auth($r, 'view');
        $cert = $this->certificate($r, $patient);
        Container::audit()->record((int) $user['id'], 'view', 'certificates', (int) $cert['id'], (int) $patient['id'], null, ['section' => 'atestados'], $r);
        return $this->showPage($user, $patient, $cert, []);
    }

    /** GET /pacientes/{id}/atestados/{certId}/pdf */
    public function pdf(Request $r): Response
    {
        [$user, $patient] = $this->auth($r, 'view');
        $cert = $this->certificate($r, $patient);
        Container::audit()->record((int) $user['id'], 'view', 'certificates', (int) $cert['id'], (int) $patient['id'], null, ['section' => 'atestados', 'pdf' => true], $r);
        $body = (string) $cert['body_html'];
        if ($cert['voided_at'] !== null) {
            $body = '<p class="atestado-anulado">ANULADO — ' . e((string) $cert['void_reason']) . '</p>' . $body;
        }
        $bytes = Container::pdf()->render(CertificateType::TITLES[$cert['type']], $body);
        return Response::pdf($bytes, PdfFilename::make('Atestado', (string) ($patient['social_name'] ?: $patient['full_name']), (string) $cert['issued_on']));
    }

    /** POST /pacientes/{id}/atestados/{certId}/anular */
    public function void(Request $r): Response
    {
        [$user, $patient] = $this->auth($r, 'edit');
        $cert = $this->certificate($r, $patient);
        $reason = $r->input('motivo', '');
        try {
            Container::certificates()->void((int) $cert['id'], (int) $user['id'], is_string($reason) ? $reason : '');
        } catch (InvalidCertificateException $e) {
            return $this->showPage($user, $patient, Container::certificates()->find((int) $cert['id']) ?? $cert, $e->errors, 422);
        }
        Container::audit()->record((int) $user['id'], 'update', 'certificates', (int) $cert['id'], (int) $patient['id'], null,
            ['section' => 'atestados', 'anulado' => true, 'motivo' => trim((string) $reason)], $r);
        Session::flash('ok', 'Atestado anulado.');
        return Response::redirect('/pacientes/' . $patient['id'] . '/atestados/' . $cert['id']);
    }

    /** @return array{0: array, 1: array} usuario e paciente */
    private function auth(Request $r, string $action): array
    {
        $user = $r->attributes['user'];
        $pid = (int) $r->params['id'];
        Clinician::assert($user, $r, $pid, 'certificates', 'atestados');
        Container::gate()->authorize($user, $action, Policy::CLINICO, $pid, $r);
        $patient = Container::patients()->find($pid);
        if ($patient === null) {
            throw new HttpException(404);
        }
        return [$user, $patient];
    }

    private function certificate(Request $r, array $patient): array
    {
        $cert = Container::certificates()->find((int) $r->params['certId']);
        if ($cert === null || (int) $cert['patient_id'] !== (int) $patient['id']) {
            throw new HttpException(404);
        }
        return $cert;
    }

    private function form(array $user, array $patient, ?string $type, array $values, array $errors, int $status = 200): Response
    {
        return $this->page('form.php', 'Novo atestado', $user, [
            'patient' => $patient, 'type' => $type, 'values' => $values, 'errors' => $errors,
        ], $status);
    }

    private function showPage(array $user, array $patient, array $cert, array $errors, int $status = 200): Response
    {
        return $this->page('show.php', CertificateType::TITLES[$cert['type']], $user, [
            'patient' => $patient, 'cert' => $cert, 'errors' => $errors,
            'voider' => $cert['voided_by'] !== null ? Container::users()->find((int) $cert['voided_by']) : null,
        ], $status);
    }

    private function page(string $view, string $title, array $user, array $data, int $status = 200): Response
    {
        return Response::html(View::render('Certificates/views/' . $view, $data + [
            'title' => $title, 'userName' => $user['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'), $status);
    }
}
