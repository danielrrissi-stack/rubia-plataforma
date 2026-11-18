<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Partes comuns das telas da agenda (equipe): papel antes de tudo (403 auditado), escolha da profissional pelo
 * AgendaAccess e montagem da pagina no layout clinico.
 */
trait AgendaPages
{
    /** Papel da equipe (a familia ganha 'view AGENDA' na Policy so para o portal). */
    private function staff(Request $r): array
    {
        $user = $r->attributes['user'];
        if (!in_array($user['role'] ?? '', ['medico', 'nutricionista', 'secretaria'], true)) {
            $this->deny($user, $r);
        }
        return $user;
    }

    /** @return never */
    private function deny(array $user, Request $r, ?int $patientId = null): void
    {
        Container::audit()->record(
            isset($user['id']) ? (int) $user['id'] : null, 'denied', 'agenda', null, $patientId, null,
            ['acao' => 'equipe', 'section' => 'agenda'], $r,
        );
        throw new HttpException(403, 'Acesso negado');
    }

    /**
     * Profissional pedida em ?prof= (ou no formulario), conferida por canView (403 auditado); sem ela, a propria
     * agenda da profissional ou a primeira da lista. null = nenhuma agenda disponivel.
     *
     * @param list<array{id:int,name:string,role:string}> $agendas
     */
    private function chosenProfessional(Request $r, array $user, array $agendas): ?int
    {
        $raw = (string) $r->input('prof', '');
        if ($raw !== '') {
            $id = filter_var($raw, FILTER_VALIDATE_INT);
            if ($id === false || !Container::agendaAccess()->canView($user, $id)) {
                $this->deny($user, $r);
            }
            return $id;
        }
        foreach ($agendas as $a) {
            if ($a['id'] === (int) $user['id']) {
                return $a['id'];
            }
        }
        return $agendas[0]['id'] ?? null;
    }

    /** Data 'Y-m-d' valida do parametro, ou o padrao. */
    private function dateParam(Request $r, string $key, string $default): string
    {
        $raw = (string) $r->input($key, '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $d !== false && $d->format('Y-m-d') === $raw ? $raw : $default;
    }

    private function page(string $template, string $title, Request $r, array $data, int $status = 200): Response
    {
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $r->attributes['user']['name'], 'active' => '/agenda',
        ], 'Layout/views/layout.php'), $status);
    }
}
