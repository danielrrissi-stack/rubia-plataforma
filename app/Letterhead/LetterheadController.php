<?php
declare(strict_types=1);

namespace App\Letterhead;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Labs\Clinician;
use App\Medications\PrescriptionPrintController;
use App\Pdf\PdfFilename;

/** Papel timbrado para preencher a mao (Receita, Pedido de Exames, Anotacoes). Nada e gravado na ficha. */
final class LetterheadController
{
    /** Mesma regra da receita: so a medica com acesso ao paciente. */
    public static function can(array $user, int $patientId): bool
    {
        return Clinician::is($user) && Container::gate()->check($user, 'view', Policy::MEDICAMENTOS, $patientId);
    }

    /** GET /admin/receita/papel/{tipo}: em branco (so administrador) */
    public function blank(Request $r): Response
    {
        $user = $r->attributes['user'];
        if ((int) ($user['is_admin'] ?? 0) !== 1) {
            throw new HttpException(403);
        }
        return $this->paper($this->type($r), $user, null);
    }

    /** GET /pacientes/{id}/papel/{tipo}: com nome, nascimento e data de hoje */
    public function patient(Request $r): Response
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Clinician::assert($user, $r, $id, 'patients', 'papel_timbrado');
        Container::gate()->authorize($user, 'view', Policy::MEDICAMENTOS, $id, $r);
        $patient = Container::patients()->find($id);
        if ($patient === null) {
            throw new HttpException(404);
        }
        $type = $this->type($r);
        Container::audit()->record((int) $user['id'], 'view', 'patients', $id, $id, null, ['section' => 'papel_timbrado', 'tipo' => $type], $r);
        return $this->paper($type, $user, $patient);
    }

    private function type(Request $r): string
    {
        $type = (string) ($r->params['tipo'] ?? '');
        if (!isset(PaperType::TITLES[$type])) {
            throw new HttpException(404);
        }
        return $type;
    }

    private function paper(string $type, array $user, ?array $patient): Response
    {
        $today = date('Y-m-d');
        $doctor = Container::db()->one('SELECT name, specialty, professional_register, rqe FROM users WHERE id = ?', [(int) $user['id']]) ?? $user;
        $html = LetterheadDoc::html($type, $doctor, PrescriptionPrintController::headSettings(), $patient, $today);
        $title = PaperType::TITLES[$type];
        $bytes = Container::pdf()->render($title, $html, LetterheadDoc::cssFile(), LetterheadDoc::fontDir());
        $who = $patient === null ? 'em branco' : (string) ($patient['social_name'] ?: $patient['full_name']);
        return Response::pdf($bytes, PdfFilename::make($title, $who, $today), true);
    }
}
