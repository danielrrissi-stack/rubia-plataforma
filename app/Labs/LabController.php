<?php
declare(strict_types=1);

namespace App\Labs;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/** Telas de exames laboratoriais da equipe (so medico): evolucao, coletas, laudos e liberacao para a familia. */
final class LabController
{
    public const GROUPS = ['glicose' => 'Glicemia e insulina', 'lipidios' => 'Lipídios', 'figado' => 'Fígado', 'outros' => 'Outros'];
    private const FILES_AGAIN = 'Escolha os arquivos do laudo de novo.';

    /** GET /pacientes/{id}/exames */
    public function index(Request $r): Response
    {
        $id = $this->authPatient($r, 'view');
        $user = $r->attributes['user'];
        $all = ($r->query['todas'] ?? '') === '1';
        Container::audit()->record((int) $user['id'], 'view', 'lab_collections', null, $id, null, ['section' => 'exames'], $r);
        return $this->page('Labs/views/index.php', 'Exames', $r, [
            'patient' => $this->patient($id),
            'evolution' => Container::labs()->evolution($id, $all ? null : 6),
            'collections' => Container::labs()->collections($id),
            'all' => $all,
        ]);
    }

    /** GET /pacientes/{id}/exames/serie.json?exame=<testId> — serie historica de um exame (so valores numericos). */
    public function series(Request $r): Response
    {
        $id = $this->authPatient($r, 'view');
        $testId = (string) ($r->query['exame'] ?? '');
        if (!ctype_digit($testId) || (int) $testId < 1) {
            throw new HttpException(404);
        }
        try {
            $s = Container::labs()->series($id, (int) $testId);
        } catch (\RuntimeException) {
            throw new HttpException(404);
        }
        $points = array_map(static fn(array $p): array => $p + [
            'label' => $p['class'] === null ? null : LabClassifier::LABEL[$p['class']],
        ], $s['points']);
        return Response::json([
            'test' => [
                'name' => (string) $s['test']['name'],
                'unit' => (string) $s['test']['unit'],
                'decimals' => (int) $s['test']['decimals'],
            ],
            'points' => $points,
            'band' => $s['band'],
        ]);
    }

    /** GET /pacientes/{id}/exames/nova */
    public function createForm(Request $r): Response
    {
        $id = $this->authPatient($r, 'edit');
        return $this->renderForm($r, $this->patient($id), null, [
            'coletado_em' => date('Y-m-d'), 'laboratorio' => '', 'observacao' => '', 'valor' => [],
        ], [], false);
    }

    /** POST /pacientes/{id}/exames/nova — coleta e laudos na mesma transacao: laudo invalido desfaz a coleta. */
    public function create(Request $r): Response
    {
        $id = $this->authPatient($r, 'edit');
        $patient = $this->patient($id);
        $uid = (int) $r->attributes['user']['id'];
        $values = $this->posted($r);
        $files = is_array($r->files['laudos'] ?? null) ? $r->files['laudos'] : [];
        try {
            $cid = Container::db()->transaction(function () use ($id, $values, $files, $uid, $r): int {
                $cid = Container::labs()->create($id, self::data($values), $values['valor'], $uid, $r);
                Container::labAttachments()->storeMany($cid, $id, $files, $uid, $r);
                return $cid;
            });
        } catch (InvalidLabException $e) {
            return $this->renderForm($r, $patient, null, $values, $e->errors, self::hasFiles($files));
        }
        Session::flash('ok', 'Coleta registrada.');
        return Response::redirect('/exames/' . $cid);
    }

    /** GET /exames/{collectionId} */
    public function show(Request $r): Response
    {
        $c = $this->authCollection($r, 'view');
        $user = $r->attributes['user'];
        $pid = (int) $c['patient_id'];
        $cid = (int) $c['id'];
        Container::audit()->record((int) $user['id'], 'view', 'lab_collections', $cid, $pid, null, ['section' => 'exames'], $r);
        $evo = Container::labs()->evolution($pid, null);
        $rows = [];
        foreach ($evo['rows'] as $row) {
            if (isset($row['cells'][$cid])) {
                $rows[] = ['test' => $row['test'], 'cell' => $row['cells'][$cid]];
            }
        }
        return $this->page('Labs/views/show.php', 'Coleta de exames', $r, [
            'collection' => $c,
            'patient' => $this->patient($pid),
            'rows' => $rows,
            'homa' => $evo['homa']['cells'][$cid]['value'] ?? null,
            'attachments' => Container::labAttachments()->forCollection($cid),
        ]);
    }

    /** GET /exames/{collectionId}/editar */
    public function editForm(Request $r): Response
    {
        $c = $this->authCollection($r, 'edit');
        $tests = [];
        foreach (Container::labCatalog()->tests(false) as $t) {
            $tests[(int) $t['id']] = $t;
        }
        $valor = [];
        foreach ($c['results'] as $res) {
            $t = $tests[$res['test_id']] ?? null;
            $valor[$res['test_id']] = $res['value_num'] === null
                ? (string) $res['value_text']
                : fmt_num($res['value_num'], self::editDecimals($res['value_num'], (int) ($t['decimals'] ?? 0)));
        }
        return $this->renderForm($r, $this->patient((int) $c['patient_id']), $c, [
            'coletado_em' => (string) $c['collected_on'],
            'laboratorio' => (string) ($c['lab_name'] ?? ''),
            'observacao' => (string) ($c['notes'] ?? ''),
            'valor' => $valor,
        ], [], false);
    }

    /** POST /exames/{collectionId}/editar */
    public function update(Request $r): Response
    {
        $c = $this->authCollection($r, 'edit');
        $values = $this->posted($r);
        try {
            Container::labs()->update((int) $c['id'], self::data($values), $values['valor'], (int) $r->attributes['user']['id'], $r);
        } catch (InvalidLabException $e) {
            return $this->renderForm($r, $this->patient((int) $c['patient_id']), $c, $values, $e->errors, false);
        }
        Session::flash('ok', 'Coleta atualizada.');
        return Response::redirect('/exames/' . $c['id']);
    }

    /** POST /exames/{collectionId}/excluir */
    public function delete(Request $r): Response
    {
        $c = $this->authCollection($r, 'edit');
        Container::labs()->delete((int) $c['id'], (int) $r->attributes['user']['id'], $r);
        Session::flash('ok', 'Coleta excluída.');
        return Response::redirect('/pacientes/' . $c['patient_id'] . '/exames');
    }

    /** POST /exames/{collectionId}/liberar */
    public function release(Request $r): Response
    {
        $c = $this->authCollection($r, 'edit');
        Container::labs()->release((int) $c['id'], (int) $r->attributes['user']['id'], $r);
        Session::flash('ok', 'Coleta liberada para a família.');
        return Response::redirect('/exames/' . $c['id']);
    }

    /** POST /exames/{collectionId}/recolher */
    public function unrelease(Request $r): Response
    {
        $c = $this->authCollection($r, 'edit');
        Container::labs()->unrelease((int) $c['id'], (int) $r->attributes['user']['id'], $r);
        Session::flash('ok', 'Coleta recolhida: a família não a vê mais.');
        return Response::redirect('/exames/' . $c['id']);
    }

    /** POST /exames/{collectionId}/anexos — envio adicional de laudos */
    public function addAttachments(Request $r): Response
    {
        $c = $this->authCollection($r, 'edit');
        $files = is_array($r->files['laudos'] ?? null) ? $r->files['laudos'] : [];
        if (!self::hasFiles($files)) {
            Session::flash('erro', 'Selecione o arquivo do laudo.');
            return Response::redirect('/exames/' . $c['id']);
        }
        try {
            $ids = Container::labAttachments()->storeMany((int) $c['id'], (int) $c['patient_id'], $files, (int) $r->attributes['user']['id'], $r);
        } catch (InvalidLabException $e) {
            Session::flash('erro', implode(' ', $e->errors));
            return Response::redirect('/exames/' . $c['id']);
        }
        Session::flash('ok', count($ids) === 1 ? 'Laudo anexado.' : count($ids) . ' laudos anexados.');
        return Response::redirect('/exames/' . $c['id']);
    }

    /** GET /exames/anexos/{attachmentId} */
    public function download(Request $r): Response
    {
        $att = $this->authAttachment($r, 'view');
        $user = $r->attributes['user'];
        try {
            $res = Container::labAttachments()->downloadResponse($att);
        } catch (\RuntimeException) {
            throw new HttpException(404);
        }
        Container::audit()->record((int) $user['id'], 'download', 'lab_attachments', (int) $att['id'], (int) $att['patient_id'], null, null, $r);
        return $res;
    }

    /** POST /exames/anexos/{attachmentId}/excluir */
    public function deleteAttachment(Request $r): Response
    {
        $att = $this->authAttachment($r, 'edit');
        Container::labAttachments()->delete((int) $att['id'], (int) $r->attributes['user']['id'], $r);
        Session::flash('ok', 'Laudo excluído.');
        return Response::redirect('/exames/' . $att['collection_id']);
    }

    // ---- internos ----

    /** Casas para re-exibir no formulario: as do catalogo, sem perder casas que o valor gravado tenha a mais. */
    private static function editDecimals(float $v, int $catalog): int
    {
        $dec = $catalog;
        while ($dec < 4 && round($v, $dec) != $v) {
            $dec++;
        }
        return $dec;
    }

    /** @return array{coletado_em:string,laboratorio:string,observacao:string,valor:array<int,string>} */
    private function posted(Request $r): array
    {
        $str = static fn(mixed $v): string => is_string($v) ? mb_scrub($v, 'UTF-8') : '';
        $valor = [];
        foreach ((array) ($r->post['valor'] ?? []) as $k => $v) {
            if (is_int($k) || ctype_digit((string) $k)) {
                $valor[(int) $k] = $str($v);
            }
        }
        return [
            'coletado_em' => trim($str($r->post['coletado_em'] ?? '')),
            'laboratorio' => $str($r->post['laboratorio'] ?? ''),
            'observacao' => $str($r->post['observacao'] ?? ''),
            'valor' => $valor,
        ];
    }

    private static function data(array $values): array
    {
        return ['collected_on' => $values['coletado_em'], 'lab_name' => $values['laboratorio'], 'notes' => $values['observacao']];
    }

    /** Algum arquivo escolhido no campo multi-arquivo laudos[]? */
    private static function hasFiles(array $field): bool
    {
        $errors = $field['error'] ?? [];
        foreach (is_array($errors) ? $errors : [$errors] as $err) {
            if (is_scalar($err) && (int) $err !== UPLOAD_ERR_NO_FILE) {
                return true;
            }
        }
        return false;
    }

    private function authPatient(Request $r, string $action): int
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Clinician::assert($user, $r, $id);
        Container::gate()->authorize($user, $action, Policy::EXAMES, $id, $r);
        $this->patient($id);
        return $id;
    }

    /** 404 (inexistente ou excluida) -> papel -> Gate. */
    private function authCollection(Request $r, string $action): array
    {
        $c = Container::labs()->find((int) $r->params['collectionId']);
        if ($c === null || $c['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        $user = $r->attributes['user'];
        Clinician::assert($user, $r, (int) $c['patient_id']);
        Container::gate()->authorize($user, $action, Policy::EXAMES, (int) $c['patient_id'], $r);
        return $c;
    }

    /** Anexo ativo de coleta nao excluida; 404 -> papel -> Gate. */
    private function authAttachment(Request $r, string $action): array
    {
        $att = Container::labAttachments()->find((int) $r->params['attachmentId']);
        $c = $att === null ? null : Container::labs()->find((int) $att['collection_id']);
        if ($att === null || $c === null || $c['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        $user = $r->attributes['user'];
        Clinician::assert($user, $r, (int) $att['patient_id']);
        Container::gate()->authorize($user, $action, Policy::EXAMES, (int) $att['patient_id'], $r);
        return $att;
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }

    private function renderForm(Request $r, array $patient, ?array $collection, array $values, array $errors, bool $filesAgain): Response
    {
        // Exames ativos + exames desativados que ja tem resultado nesta coleta (editaveis; o resultado nao se perde).
        $inCollection = [];
        foreach ($collection['results'] ?? [] as $res) {
            $inCollection[(int) $res['test_id']] = true;
        }
        $groups = [];
        foreach (Container::labCatalog()->tests(false) as $t) {
            if ((int) $t['active'] !== 1 && !isset($inCollection[(int) $t['id']])) {
                continue;
            }
            $g = isset(self::GROUPS[$t['group_name']]) ? (string) $t['group_name'] : 'outros';
            $groups[$g][] = $t;
        }
        $ordered = [];
        foreach (self::GROUPS as $g => $_) {
            if (isset($groups[$g])) {
                $ordered[$g] = $groups[$g];
            }
        }
        return $this->page('Labs/views/form.php', $collection === null ? 'Nova coleta' : 'Editar coleta', $r, [
            'patient' => $patient,
            'collection' => $collection,
            'values' => $values,
            'errors' => $errors,
            'groups' => $ordered,
            'filesAgain' => $filesAgain ? self::FILES_AGAIN : null,
        ]);
    }

    private function page(string $template, string $title, Request $r, array $data): Response
    {
        $user = $r->attributes['user'];
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $user['name'], 'active' => '/pacientes',
        ], 'Layout/views/layout.php'));
    }
}
