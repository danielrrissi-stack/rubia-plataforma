<?php
declare(strict_types=1);

namespace App\Diary;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Diario de habitos, telas da equipe: pedidos de registro. Medica faz tudo; nutricionista so pedidos nutricionais,
 * nao sigilosos, de pacientes liberados a ela. Ordem: papel (403) -> 404 do pedido -> Gate (403) -> visibilidade (404)
 * -> mayEdit (403). Familia e crianca usam o portal (403 aqui).
 */
final class DiaryController
{
    private const STAFF = ['medico', 'nutricionista'];

    /** GET /pacientes/{id}/diario */
    public function index(Request $r): Response
    {
        $pid = (int) $r->params['id'];
        $user = $this->staff($r, $pid);
        $patient = $this->patient($pid);
        $this->authorizePatient($user, $r, $pid, 'view');
        Container::audit()->record((int) $user['id'], 'view', 'diary', null, $pid, null, ['section' => 'diario'], $r);

        $lists = Container::diaryRequests()->listForStaff($pid, $user, date('Y-m-d'));
        return $this->page('Diary/views/index.php', 'Diário', $r, [
            'patient' => $patient, 'current' => $lists['current'], 'ended' => $lists['ended'], 'today' => date('Y-m-d'),
            'canEdit' => self::allowed($user, 'edit', $pid),
        ]);
    }

    /** GET /pacientes/{id}/diario/pedidos/novo */
    public function createForm(Request $r): Response
    {
        $pid = (int) $r->params['id'];
        $user = $this->staff($r, $pid);
        $patient = $this->patient($pid);
        $this->authorizePatient($user, $r, $pid, 'edit');
        return $this->form($r, $user, $patient, null, [
            'type' => '', 'weekdays' => DiaryType::ALL_DAYS, 'start_date' => date('Y-m-d'), 'end_date' => '', 'guidance' => '', 'secret' => 0,
        ], []);
    }

    /** POST /pacientes/{id}/diario/pedidos/novo */
    public function create(Request $r): Response
    {
        $pid = (int) $r->params['id'];
        $user = $this->staff($r, $pid);
        $patient = $this->patient($pid);
        $this->authorizePatient($user, $r, $pid, 'edit');
        $data = $this->input($r);
        try {
            Container::diaryRequests()->create($pid, $data, $user, date('Y-m-d'), $r);
        } catch (InvalidDiaryException $e) {
            return $this->form($r, $user, $patient, null, $data, $e->errors());
        }
        Session::flash('ok', 'Pedido criado.');
        return Response::redirect('/pacientes/' . $pid . '/diario');
    }

    /** GET /diario/pedidos/{requestId}/editar */
    public function editForm(Request $r): Response
    {
        [$user, $req, $patient] = $this->openRequest($r);
        return $this->form($r, $user, $patient, $req, $req, []);
    }

    /** POST /diario/pedidos/{requestId}/editar */
    public function update(Request $r): Response
    {
        [$user, $req, $patient] = $this->openRequest($r);
        $data = $this->input($r);
        try {
            Container::diaryRequests()->update((int) $req['id'], $data, $user, date('Y-m-d'), $r);
        } catch (InvalidDiaryException $e) {
            return $this->form($r, $user, $patient, $req, $data, $e->errors());
        }
        Session::flash('ok', 'Pedido salvo.');
        return Response::redirect('/pacientes/' . (int) $req['patient_id'] . '/diario');
    }

    /** POST /diario/pedidos/{requestId}/encerrar */
    public function end(Request $r): Response
    {
        [$user, $req] = $this->openRequest($r);
        try {
            Container::diaryRequests()->end((int) $req['id'], (int) $user['id'], date('Y-m-d'), $r);
            Session::flash('ok', 'Pedido encerrado.');
        } catch (InvalidDiaryException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível encerrar o pedido.');
        }
        return Response::redirect('/pacientes/' . (int) $req['patient_id'] . '/diario');
    }

    /** POST /diario/pedidos/{requestId}/excluir */
    public function delete(Request $r): Response
    {
        [$user, $req] = $this->openRequest($r);
        try {
            Container::diaryRequests()->delete((int) $req['id'], (int) $user['id'], $r);
            Session::flash('ok', 'Pedido excluído.');
        } catch (InvalidDiaryException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível excluir o pedido.');
        }
        return Response::redirect('/pacientes/' . (int) $req['patient_id'] . '/diario');
    }

    // ---- ficha do paciente ----

    /**
     * Cartao Diario da ficha: so os pedidos vigentes visiveis ao usuario; null se nao e equipe ou nao alcanca o paciente.
     *
     * @return ?array{requests: list<array>}
     */
    public static function card(array $user, int $patientId, string $today): ?array
    {
        if (!in_array($user['role'], self::STAFF, true) || !self::allowed($user, 'view', $patientId)) {
            return null;
        }
        $current = Container::diaryRequests()->listForStaff($patientId, $user, $today)['current'];
        $started = array_values(array_filter($current, fn (array $p): bool => (string) $p['start_date'] <= $today));
        return ['requests' => $started];
    }

    // ---- helpers ----

    /** Medica: secao DIARIO; nutricionista nao tem DIARIO na Policy, entao vale o acesso de NUTRICAO ao paciente liberado. */
    private static function allowed(array $user, string $action, int $pid): bool
    {
        $section = $user['role'] === 'medico' ? Policy::DIARIO : Policy::NUTRICAO;
        return Container::gate()->check($user, $action, $section, $pid);
    }

    /** Papel da equipe, antes do Gate (a familia ganha 'view DIARIO' para o portal). */
    private function staff(Request $r, ?int $pid): array
    {
        $user = $r->attributes['user'];
        if (!in_array($user['role'] ?? '', self::STAFF, true)) {
            $this->deny($user, $r, $pid);
        }
        return $user;
    }

    private function authorizePatient(array $user, Request $r, int $pid, string $action): void
    {
        if (!self::allowed($user, $action, $pid)) {
            $this->deny($user, $r, $pid);
        }
    }

    /** @return never */
    private function deny(array $user, Request $r, ?int $pid): void
    {
        Container::audit()->record(
            isset($user['id']) ? (int) $user['id'] : null, 'denied', 'diary', null, $pid, null,
            ['acao' => 'equipe', 'section' => 'diario'], $r,
        );
        throw new HttpException(403, 'Acesso negado');
    }

    private function patient(int $pid): array
    {
        $p = Container::patients()->find($pid);
        if ($p === null || $p['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        return $p;
    }

    /** @return array{0:array,1:array,2:array} usuario, pedido e paciente (papel -> 404 -> Gate -> visibilidade 404 -> mayEdit 403) */
    private function openRequest(Request $r): array
    {
        $user = $this->staff($r, null);
        $found = Container::diaryRequests()->find((int) $r->params['requestId']);
        if ($found === null || $found['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        $pid = (int) $found['patient_id'];
        $patient = $this->patient($pid);
        $this->authorizePatient($user, $r, $pid, 'edit');
        $req = Container::diaryRequests()->visibleTo((int) $found['id'], $user);
        if ($req === null) {
            throw new HttpException(404);
        }
        if (!Container::diaryRequests()->mayEdit($req, $user)) {
            $this->deny($user, $r, $pid);
        }
        return [$user, $req, $patient];
    }

    private function input(Request $r): array
    {
        $days = $r->input('weekdays', []);
        return [
            'type' => (string) $r->input('type', ''),
            'weekdays' => is_array($days) ? array_values(array_map('strval', $days)) : [],
            'start_date' => (string) $r->input('start_date', ''),
            'end_date' => (string) $r->input('end_date', ''),
            'guidance' => (string) $r->input('guidance', ''),
            'secret' => (string) $r->input('secret', '') === '1' ? 1 : 0,
            'secret_shown' => (string) $r->input('secret_shown', '') === '1' ? 1 : 0,
        ];
    }

    private function form(Request $r, array $user, array $patient, ?array $req, array $values, array $errors): Response
    {
        $mask = $values['weekdays'] ?? 0;
        if (is_array($mask)) {
            $m = 0;
            foreach ($mask as $n) {
                $n = (int) $n;
                if ($n >= 1 && $n <= 7) {
                    $m |= 1 << ($n - 1);
                }
            }
            $mask = $m;
        }
        $values['weekdays'] = (int) $mask;
        $types = $user['role'] === 'medico' ? DiaryType::TYPES : DiaryType::NUTRITIONAL;
        $repo = Container::diaryRequests();
        return $this->page('Diary/views/request_form.php', $req === null ? 'Novo pedido de registro' : 'Editar pedido de registro', $r, [
            'patient' => $patient, 'request' => $req, 'values' => $values, 'errors' => $errors, 'types' => $types,
            'lockType' => $req !== null && $repo->hasEntries((int) $req['id']),
            'canSecret' => $repo->mayMarkSecret($user, (int) $patient['id'], date('Y-m-d')) || (int) ($req['secret'] ?? 0) === 1,
        ], $errors === [] ? 200 : 422);
    }

    private function page(string $template, string $title, Request $r, array $data, int $status = 200): Response
    {
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $r->attributes['user']['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'), $status);
    }
}
