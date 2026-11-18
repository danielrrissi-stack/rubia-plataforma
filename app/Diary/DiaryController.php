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

        $today = date('Y-m-d');
        $monday = $this->weekOf($r, $today);
        $grid = Container::diaryWeek()->grid($pid, $user, $monday);
        $sunday = $grid['days'][6];
        $entries = Container::diaryEntries()->forRange($pid, $user, $monday, $sunday);
        $comments = Container::diaryComments()->forEntries(array_map(fn (array $e): int => (int) $e['id'], $entries));
        $requests = [];
        foreach ($grid['rows'] as $row) {
            $requests[(int) $row['request']['id']] = $row['request'];
        }
        $byDay = [];
        $plans = [];
        $repo = Container::diaryRequests();
        foreach ($entries as $e) {
            $day = (string) $e['day'];
            $e['may_comment'] = isset($requests[(int) $e['request_id']]) && $repo->mayEdit($requests[(int) $e['request_id']], $user);
            $e['comments'] = $comments[(int) $e['id']] ?? [];
            $byDay[$day][] = $e;
            if ($e['type'] === 'alimentacao' && !array_key_exists($day, $plans)) {
                $plans[$day] = Container::mealPlans()->onDate($pid, $day);
            }
        }
        $lists = $repo->listForStaff($pid, $user, $today);
        return $this->page('Diary/views/week.php', 'Diário', $r, [
            'patient' => $patient, 'current' => $lists['current'], 'ended' => $lists['ended'], 'today' => $today,
            'canEdit' => self::allowed($user, 'edit', $pid),
            'grid' => $grid, 'monday' => $monday, 'byDay' => $byDay, 'plans' => $plans, 'userId' => (int) $user['id'],
            'prev' => (new \DateTimeImmutable($monday))->modify('-7 days')->format('Y-m-d'),
            'next' => (new \DateTimeImmutable($monday))->modify('+7 days')->format('Y-m-d'),
        ]);
    }

    /** POST /diario/registros/{entryId}/comentar */
    public function comment(Request $r): Response
    {
        [$user, $entry] = $this->openEntry($r, (int) $r->params['diaryEntryId']);
        try {
            Container::diaryComments()->add((int) $entry['id'], (string) $r->input('body', ''), $user, $r);
            Session::flash('ok', 'Comentário publicado.');
        } catch (InvalidDiaryException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível comentar.');
        }
        return $this->backToDay($entry);
    }

    /** POST /diario/comentarios/{commentId}/editar */
    public function editComment(Request $r): Response
    {
        [$user, $entry, $comment] = $this->openComment($r);
        try {
            Container::diaryComments()->update((int) $comment['id'], (string) $r->input('body', ''), $user, $r);
            Session::flash('ok', 'Comentário salvo.');
        } catch (InvalidDiaryException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível salvar o comentário.');
        }
        return $this->backToDay($entry);
    }

    /** POST /diario/comentarios/{commentId}/excluir */
    public function deleteComment(Request $r): Response
    {
        [$user, $entry, $comment] = $this->openComment($r);
        try {
            Container::diaryComments()->delete((int) $comment['id'], $user, $r);
            Session::flash('ok', 'Comentário excluído.');
        } catch (InvalidDiaryException $e) {
            Session::flash('erro', $e->errors()[0] ?? 'Não foi possível excluir o comentário.');
        }
        return $this->backToDay($entry);
    }

    /** GET /diario/registros/{entryId}/foto (leitura nao auditada) */
    public function photo(Request $r): Response
    {
        [, $entry] = $this->openEntry($r, (int) $r->params['diaryEntryId'], 'view');
        if (($entry['photo_path'] ?? null) === null || $entry['photo_path'] === '') {
            throw new HttpException(404);
        }
        return Container::diaryPhotos()->response($entry);
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
     * @return ?array{requests: list<array>, week: array{filled: int, asked: int}}
     */
    public static function card(array $user, int $patientId, string $today): ?array
    {
        if (!in_array($user['role'], self::STAFF, true) || !self::allowed($user, 'view', $patientId)) {
            return null;
        }
        $current = Container::diaryRequests()->listForStaff($patientId, $user, $today)['current'];
        $started = array_values(array_filter($current, fn (array $p): bool => (string) $p['start_date'] <= $today));
        return ['requests' => $started, 'week' => Container::diaryWeek()->lastSevenDays($patientId, $user, $today)];
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

    /** Registro por id: papel -> 404 -> Gate -> visibilidade 404. @return array{0:array,1:array} usuario e registro */
    private function openEntry(Request $r, int $entryId, string $action = 'edit'): array
    {
        $user = $this->staff($r, null);
        $found = Container::diaryEntries()->find($entryId);
        if ($found === null) {
            throw new HttpException(404);
        }
        $pid = (int) $found['patient_id'];
        $this->patient($pid);
        $this->authorizePatient($user, $r, $pid, $action);
        $entry = Container::diaryEntries()->visibleTo($entryId, $user);
        if ($entry === null) {
            throw new HttpException(404);
        }
        return [$user, $entry];
    }

    /** @return array{0:array,1:array,2:array} usuario, registro e comentario */
    private function openComment(Request $r): array
    {
        $comment = Container::diaryComments()->find((int) $r->params['commentId']);
        if ($comment === null || $comment['deleted_at'] !== null) {
            $this->staff($r, null);
            throw new HttpException(404);
        }
        [$user, $entry] = $this->openEntry($r, (int) $comment['entry_id']);
        return [$user, $entry, $comment];
    }

    private function backToDay(array $entry): Response
    {
        $day = (string) $entry['day'];
        return Response::redirect('/pacientes/' . (int) $entry['patient_id'] . '/diario?semana=' . DiaryWeek::monday($day) . '#dia-' . $day);
    }

    /** Segunda-feira pedida em ?semana=AAAA-MM-DD; invalida ou ausente = semana atual. */
    private function weekOf(Request $r, string $today): string
    {
        $raw = (string) $r->input('semana', '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $valid = $d !== false && $d->format('Y-m-d') === $raw;
        return DiaryWeek::monday($valid ? $raw : $today);
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
