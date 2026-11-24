<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Access\Policy;
use App\Core\Container;

/** Cartoes "Consultas" da ficha (equipe) e do portal (familia). Nunca expõe observacao nem motivo de cancelamento. */
final class AgendaCards
{
    private const STAFF = ['medico', 'nutricionista', 'secretaria'];

    /**
     * Ficha: null sem permissao. Nutricionista ve so as consultas dela; medica e secretaria, de todas as profissionais.
     * `bookProf`: agenda do botao "Agendar" (AgendaAccess::firstBookableAgenda), null = sem botao.
     * @return ?array{upcoming: list<array>, past: list<array>, canBook: bool, bookProf: ?int}
     */
    public static function forStaff(array $user, int $patientId, string $now): ?array
    {
        if (!in_array($user['role'] ?? '', self::STAFF, true) || !Container::gate()->check($user, 'view', Policy::AGENDA, $patientId)) {
            return null;
        }
        $only = $user['role'] === 'nutricionista' ? (int) $user['id'] : null;
        $found = Container::appointments()->forPatient($patientId, $only, $now);
        $access = Container::agendaAccess();
        $link = static function (array $a) use ($user, $access): array {
            return self::row($a) + ['link' => $access->canManageExisting($user, (int) $a['professional_id'])];
        };
        $bookProf = $access->firstBookableAgenda($user, $patientId);
        return [
            'upcoming' => array_map($link, $found['upcoming']),
            'past' => array_map($link, $found['past']),
            'canBook' => $bookProf !== null,
            'bookProf' => $bookProf,
        ];
    }

    /**
     * Portal: null para a conta da crianca ou sem permissao. So data, hora, profissional e situacao.
     * @return ?array{upcoming: list<array>, past: list<array>}
     */
    public static function forFamily(array $user, int $patientId, string $now): ?array
    {
        if (!in_array($user['role'] ?? '', ['paciente', 'responsavel'], true) || !Container::gate()->check($user, 'view', Policy::AGENDA, $patientId)) {
            return null;
        }
        $found = Container::appointments()->forPatient($patientId, null, $now);
        return ['upcoming' => array_map([self::class, 'row'], $found['upcoming']), 'past' => array_map([self::class, 'row'], $found['past'])];
    }

    /** @return array{id:int,date:string,time:string,professional:string,status:string,kind:string} */
    private static function row(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'date' => substr((string) $a['starts_at'], 0, 10),
            'time' => substr((string) $a['starts_at'], 11, 5),
            'professional' => (string) $a['professional_name'],
            'status' => AgendaStatus::label((string) $a['status']),
            'kind' => AgendaStatus::kindLabel((string) $a['kind']),
        ];
    }
}
