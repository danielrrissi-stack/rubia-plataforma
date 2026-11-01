<?php
declare(strict_types=1);

namespace App\Access;

use App\Audit\AuditLog;
use App\Core\HttpException;
use App\Core\Request;

/** Ponto unico de autorizacao: Policy (papel/secao) + PatientScope (paciente). */
final class Gate
{
    public function __construct(
        private Policy $policy,
        private PatientScope $scope,
        private AuditLog $audit,
    ) {
    }

    /** Sem lancar nem auditar (para mostrar/ocultar menus). */
    public function check(array $user, string $action, string $section, ?int $patientId): bool
    {
        if (!$this->policy->allows($user, $action, $section)) {
            return false;
        }
        return $patientId === null || $this->scope->canAccess($user, $patientId);
    }

    /** @throws HttpException 403 (e audita 'denied') */
    public function authorize(array $user, string $action, string $section, ?int $patientId, Request $r): void
    {
        if ($this->check($user, $action, $section, $patientId)) {
            return;
        }
        $this->audit->record(
            isset($user['id']) ? (int) $user['id'] : null,
            'denied',
            $section,
            null,
            $patientId,
            null,
            ['acao' => $action],
            $r,
        );
        throw new HttpException(403, 'Acesso negado');
    }
}
