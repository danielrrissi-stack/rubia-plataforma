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

/** Diario no portal: paciente adulto e responsavel anotam o que a equipe pediu. Pedido sigiloso nunca deixa rastro (404). */
final class PortalDiaryController
{
    private const FAMILY = ['paciente', 'responsavel'];
    private const STAFF = ['medico', 'nutricionista', 'secretaria'];
    private const WINDOW_DAYS = 7;
    private const FIELDS = ['meal', 'meal_time', 'description', 'glasses', 'slept_at', 'woke_at', 'sleep_quality', 'activity', 'minutes'];

    /** GET /portal/p/{id}/diario */
    public function index(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $today = date('Y-m-d');
        $day = $this->dayOf($r, $today);
        Container::audit()->record((int) $user['id'], 'view', 'diary', null, $pid, null, ['section' => 'diario', 'portal' => true], $r);

        $repo = Container::diaryRequests();
        $entries = Container::diaryEntries()->forRange($pid, $user, $day, $day);
        $comments = Container::diaryComments()->forEntries(array_map(static fn (array $e): int => (int) $e['id'], $entries));
        $byRequest = [];
        foreach ($entries as $e) {
            $e['comments'] = $comments[(int) $e['id']] ?? [];
            $e['may_change'] = $this->mayChange($e, $user, $today);
            $byRequest[(int) $e['request_id']][] = $e;
        }
        $items = [];
        foreach ($repo->forViewer($pid, $user, $day, $day) as $req) {
            if ($repo->appliesOn($req, $day)) {
                $items[] = ['request' => $req, 'entries' => $byRequest[(int) $req['id']] ?? []];
            }
        }
        $measured = isset(Container::diaryWeek()->measuredDays($pid, $day, $day)[$day]);
        $links = [];
        for ($i = 0; $i <= self::WINDOW_DAYS; $i++) {
            $links[] = date('Y-m-d', strtotime($today . ' -' . $i . ' days'));
        }
        return $this->render('Portal/views/diary.php', 'Diário', $user, $patient, [
            'items' => $items, 'day' => $day, 'today' => $today, 'links' => $links, 'measured' => $measured,
        ]);
    }

    /** GET /portal/p/{id}/diario/{requestId}/anotar */
    public function form(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $req = $this->request($r, $user, $patient);
        $day = $this->dayOf($r, date('Y-m-d'));
        $values = [];
        if (in_array((string) $req['type'], DiaryType::ONE_PER_DAY, true)) {
            foreach (Container::diaryEntries()->forRange((int) $patient['id'], $user, $day, $day) as $e) {
                if ((int) $e['request_id'] === (int) $req['id']) {
                    $values = $this->valuesOf($e);
                    break;
                }
            }
        }
        return $this->formPage($user, $patient, $req, null, $day, $values, [], 200);
    }

    /** POST /portal/p/{id}/diario/{requestId}/anotar */
    public function save(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $pid = (int) $patient['id'];
        $req = $this->request($r, $user, $patient);
        $day = $this->dayOf($r, date('Y-m-d'), true);
        $values = $this->posted($r);
        try {
            Container::diaryEntries()->record((int) $req['id'], $day, $values, $this->upload($r), $user, date('Y-m-d'), $r);
        } catch (InvalidDiaryException $e) {
            return $this->formPage($user, $patient, $req, null, $day, $values, $e->errors(), 422);
        }
        Session::flash('ok', 'Anotado.');
        return Response::redirect('/portal/p/' . $pid . '/diario?dia=' . $day);
    }

    /** GET /portal/p/{id}/diario/registros/{entryId}/editar */
    public function editForm(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $entry = $this->entry($r, $user, $patient, true);
        $req = Container::diaryRequests()->find((int) $entry['request_id']);
        return $this->formPage($user, $patient, $req, $entry, (string) $entry['day'], $this->valuesOf($entry), [], 200);
    }

    /** POST /portal/p/{id}/diario/registros/{entryId}/editar */
    public function update(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $entry = $this->entry($r, $user, $patient, true);
        $values = $this->posted($r);
        try {
            Container::diaryEntries()->update(
                (int) $entry['id'], $values, $this->upload($r), (string) ($r->post['remover_foto'] ?? '') === '1', $user, date('Y-m-d'), $r,
            );
        } catch (InvalidDiaryException $e) {
            $req = Container::diaryRequests()->find((int) $entry['request_id']);
            return $this->formPage($user, $patient, $req, $entry, (string) $entry['day'], $values, $e->errors(), 422);
        }
        Session::flash('ok', 'Anotado.');
        return Response::redirect('/portal/p/' . (int) $patient['id'] . '/diario?dia=' . $entry['day']);
    }

    /** POST /portal/p/{id}/diario/registros/{entryId}/remover */
    public function remove(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $entry = $this->entry($r, $user, $patient, true);
        try {
            Container::diaryEntries()->remove((int) $entry['id'], $user, date('Y-m-d'), $r);
        } catch (InvalidDiaryException) {
            throw new HttpException(404);
        }
        Session::flash('ok', 'Registro removido.');
        return Response::redirect('/portal/p/' . (int) $patient['id'] . '/diario?dia=' . $entry['day']);
    }

    /** GET /portal/p/{id}/diario/fotos/{entryId} (leitura nao auditada) */
    public function photo(Request $r): Response
    {
        [$user, $patient, $redirect] = $this->open($r);
        if ($redirect !== null) {
            return $redirect;
        }
        $entry = $this->entry($r, $user, $patient, false);
        if (($entry['photo_path'] ?? null) === null || $entry['photo_path'] === '') {
            throw new HttpException(404);
        }
        return Container::diaryPhotos()->response($entry);
    }

    /** Cartao do inicio: ha pedido visivel que vale em algum dia de hoje-7 a hoje. */
    public static function hasRequests(array $user, int $pid, string $today): bool
    {
        if (!Container::policy()->allows($user, 'view', Policy::DIARIO)) {
            return false;
        }
        $from = date('Y-m-d', strtotime($today . ' -' . self::WINDOW_DAYS . ' days'));
        $repo = Container::diaryRequests();
        foreach ($repo->forViewer($pid, $user, $from, $today) as $req) {
            for ($i = 0; $i <= self::WINDOW_DAYS; $i++) {
                if ($repo->appliesOn($req, date('Y-m-d', strtotime($today . ' -' . $i . ' days')))) {
                    return true;
                }
            }
        }
        return false;
    }

    // ---- helpers ----

    /** Pedido visivel, do paciente da URL e com registro proprio; senao 404. */
    private function request(Request $r, array $user, array $patient): array
    {
        $req = Container::diaryRequests()->visibleTo((int) $r->params['requestId'], $user);
        if ($req === null || (int) $req['patient_id'] !== (int) $patient['id'] || (string) $req['type'] === 'medidas') {
            throw new HttpException(404);
        }
        return $req;
    }

    /** Registro visivel do paciente da URL; com $change, so se esta familia pode corrigir/apagar hoje. */
    private function entry(Request $r, array $user, array $patient, bool $change): array
    {
        $entry = Container::diaryEntries()->visibleTo((int) $r->params['diaryEntryId'], $user);
        if ($entry === null || (int) $entry['patient_id'] !== (int) $patient['id']) {
            throw new HttpException(404);
        }
        if ($change && !$this->mayChange($entry, $user, date('Y-m-d'))) {
            throw new HttpException(404);
        }
        return $entry;
    }

    /** Janela de 7 dias; em tipos de varios por dia, so quem anotou. */
    private function mayChange(array $entry, array $user, string $today): bool
    {
        $day = (string) $entry['day'];
        if ($day > $today || $day < date('Y-m-d', strtotime($today . ' -' . self::WINDOW_DAYS . ' days'))) {
            return false;
        }
        return in_array((string) $entry['type'], DiaryType::ONE_PER_DAY, true) || (int) $entry['recorded_by'] === (int) $user['id'];
    }

    /** ?dia= (no POST, tambem o campo do formulario); invalido ou fora da janela = hoje. Com $keep, data valida passa e o repositorio decide. */
    private function dayOf(Request $r, string $today, bool $keep = false): string
    {
        $raw = $r->query['dia'] ?? ($keep ? ($r->post['dia'] ?? '') : '');
        $raw = is_string($raw) ? $raw : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $valid = $d !== false && $d->format('Y-m-d') === $raw;
        if ($keep) {
            return $valid ? $raw : $today;
        }
        $from = date('Y-m-d', strtotime($today . ' -' . self::WINDOW_DAYS . ' days'));
        return $valid && $raw >= $from && $raw <= $today ? $raw : $today;
    }

    /** @return array<string,string> */
    private function posted(Request $r): array
    {
        $out = [];
        foreach (self::FIELDS as $k) {
            $v = $r->post[$k] ?? '';
            $out[$k] = is_string($v) ? mb_scrub($v, 'UTF-8') : '';
        }
        return $out;
    }

    private function upload(Request $r): ?array
    {
        $f = $r->files['foto'] ?? null;
        if (!is_array($f) || !isset($f['error']) || (int) $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    /** @return array<string,string> valores do formulario a partir de um registro (horas sem segundos) */
    private function valuesOf(array $e): array
    {
        $out = [];
        foreach (self::FIELDS as $k) {
            $v = (string) ($e[$k] ?? '');
            $out[$k] = in_array($k, ['meal_time', 'slept_at', 'woke_at'], true) ? substr($v, 0, 5) : $v;
        }
        return $out;
    }

    private function formPage(array $user, array $patient, array $req, ?array $entry, string $day, array $values, array $errors, int $status): Response
    {
        return $this->render('Portal/views/diary_entry_form.php', 'Anotar', $user, $patient, [
            'request' => $req, 'entry' => $entry, 'day' => $day, 'values' => $values, 'errors' => $errors,
        ], $status);
    }

    private function render(string $view, string $title, array $user, array $patient, array $data, int $status = 200): Response
    {
        return Response::html(View::render($view, $data + [
            'title' => $title, 'userName' => $user['name'], 'registerSw' => true,
            'patient' => $patient, 'first' => $this->firstName($patient), 'userId' => (int) $user['id'],
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
        Container::gate()->authorize($user, 'view', Policy::DIARIO, $id, $r);
        $patient = Container::patients()->find($id);
        if ($patient === null || $patient['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        return [$user, $patient, null];
    }

    private function firstName(array $p): string
    {
        $name = trim((string) ($p['social_name'] ?: $p['full_name']));
        return explode(' ', $name)[0] ?? $name;
    }
}
