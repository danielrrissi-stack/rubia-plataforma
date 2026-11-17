<?php
declare(strict_types=1);

namespace App\Nutrition;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class MealPlanController
{
    private const DEFAULT_STRUCTURE = ['v' => 1, 'meals' => []];

    /** GET /pacientes/{id}/plano */
    public function index(Request $r): Response
    {
        $id = $this->authPatient($r, 'view');
        return $this->renderIndex($r, $id);
    }

    /** POST /pacientes/{id}/plano/novo */
    public function create(Request $r): Response
    {
        $id = $this->authPatient($r, 'edit');
        $user = $r->attributes['user'];
        $repo = Container::mealPlans();
        $mode = ($r->post['modo'] ?? '') === 'estruturado' ? 'estruturado' : 'texto';
        $origin = is_string($r->post['origem'] ?? null) ? $r->post['origem'] : 'branco';
        $templateId = is_string($r->post['modelo_id'] ?? null) ? (int) $r->post['modelo_id'] : 0;

        $draft = $repo->draft($id);
        if ($draft !== null) {
            if (($r->post['descartar_rascunho'] ?? '') !== '1') {
                return $this->renderIndex($r, $id, [
                    'askDraft' => $draft,
                    'posted' => ['modo' => $mode, 'origem' => $origin, 'modelo_id' => $templateId],
                ]);
            }
            try {
                $repo->discard((int) $draft['id'], (int) $user['id'], $r);
            } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
                Session::flash('erro', $e->getMessage());
                return Response::redirect('/pacientes/' . $id . '/plano');
            }
        }

        try {
            if ($origin === 'vigente') {
                $current = $repo->current($id);
                if ($current === null) {
                    Session::flash('erro', 'Este paciente não tem plano vigente para copiar.');
                    return Response::redirect('/pacientes/' . $id . '/plano');
                }
                $planId = $repo->draftFrom((int) $current['id'], (int) $user['id'], $r);
            } elseif ($origin === 'modelo') {
                $tpl = Container::db()->one('SELECT * FROM meal_plan_templates WHERE id = ? AND deleted_at IS NULL', [$templateId]);
                if ($tpl === null) {
                    Session::flash('erro', 'Escolha um modelo válido.');
                    return Response::redirect('/pacientes/' . $id . '/plano');
                }
                $structure = $tpl['structure_json'] === null ? null : json_decode((string) $tpl['structure_json'], true);
                $planId = $repo->createDraft($id, (string) $tpl['mode'], [
                    'title' => (string) $tpl['title'],
                    'body_text' => $tpl['body_text'],
                    'guidance_text' => $tpl['guidance_text'],
                    'structure' => is_array($structure) ? $structure : null,
                ], (int) $user['id'], $r, (int) $tpl['id']);
            } else {
                $planId = $repo->createDraft($id, $mode, [
                    'title' => 'Plano alimentar - ' . date('d/m/Y'),
                    'body_text' => null,
                    'guidance_text' => null,
                    'structure' => $mode === 'estruturado' ? self::DEFAULT_STRUCTURE : null,
                ], (int) $user['id'], $r);
            }
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
            return Response::redirect('/pacientes/' . $id . '/plano');
        }
        return Response::redirect('/planos/' . $planId . '/editar');
    }

    /** GET /planos/{planId} */
    public function show(Request $r): Response
    {
        $plan = $this->authPlan($r, 'view');
        $user = $r->attributes['user'];
        Container::audit()->record((int) $user['id'], 'view', 'meal_plans', (int) $plan['id'], (int) $plan['patient_id'], null, ['section' => 'nutricao'], $r);
        return $this->renderShow($r, $plan);
    }

    /** GET /planos/{planId}/imprimir */
    public function print(Request $r): Response
    {
        $plan = $this->authPlan($r, 'view');
        $user = $r->attributes['user'];
        Container::audit()->record((int) $user['id'], 'view', 'meal_plans', (int) $plan['id'], (int) $plan['patient_id'], null, ['section' => 'nutricao', 'print' => true], $r);
        return Response::html(View::render('Nutrition/views/print.php', [
            'plan' => $plan,
            'patient' => $this->patient((int) $plan['patient_id']),
            'withTotals' => ($r->query['totais'] ?? '') === '1',
            'title' => 'Plano alimentar',
        ], null));
    }

    /** GET /planos/{planId}/editar */
    public function edit(Request $r): Response
    {
        $plan = $this->authPlan($r, 'edit');
        if ($plan['status'] !== 'rascunho') {
            return Response::redirect('/planos/' . $plan['id']);
        }
        return $this->renderEditor($r, $plan, [
            'title' => (string) $plan['title'],
            'corpo' => (string) ($plan['body_text'] ?? ''),
            'orientacoes' => (string) ($plan['guidance_text'] ?? ''),
            'revisao' => (int) $plan['revision'],
        ], []);
    }

    /** POST /planos/{planId}/editar (modo texto; o estruturado e da Task 7) */
    public function save(Request $r): Response
    {
        $plan = $this->authPlan($r, 'edit');
        $user = $r->attributes['user'];
        if ($plan['status'] !== 'rascunho') {
            return Response::redirect('/planos/' . $plan['id']);
        }
        if ($plan['mode'] !== 'texto') {
            return Response::redirect('/planos/' . $plan['id'] . '/editar');
        }
        $post = $r->post;
        $errors = [];
        $titleRaw = $post['titulo'] ?? null;
        $title = is_string($titleRaw) ? trim(mb_scrub($titleRaw, 'UTF-8')) : '';
        $len = mb_strlen($title, 'UTF-8');
        if ($len < 2 || $len > 150) {
            $errors[] = 'Informe o título do plano (2 a 150 caracteres).';
        }
        foreach (['corpo' => 'O texto do plano', 'orientacoes' => 'As orientações'] as $k => $label) {
            if (isset($post[$k]) && !is_string($post[$k])) {
                $errors[] = $label . ' é inválido.';
            }
        }
        $body = self::clean($post['corpo'] ?? '');
        $guidance = self::clean($post['orientacoes'] ?? '');
        $base = isset($post['revisao']) && is_string($post['revisao']) && ctype_digit($post['revisao']) ? (int) $post['revisao'] : null;
        $values = ['title' => $title, 'corpo' => $body, 'orientacoes' => $guidance, 'revisao' => $base ?? (int) $plan['revision']];
        if ($errors !== []) {
            return $this->renderEditor($r, $plan, $values, $errors);
        }
        try {
            $res = Container::mealPlans()->saveDraft((int) $plan['id'], [
                'title' => $title, 'body_text' => $body, 'guidance_text' => $guidance, 'structure' => null,
            ], (int) $user['id'], $r, false, $base);
            if (($post['acao'] ?? '') === 'publicar' && !$res['conflict']) {
                Container::mealPlans()->publish((int) $plan['id'], (int) $user['id'], $r);
                Session::flash('ok', 'Plano publicado.');
                return Response::redirect('/pacientes/' . $plan['patient_id'] . '/plano');
            }
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            return $this->renderEditor($r, $plan, $values, [$e->getMessage()]);
        }
        if ($res['conflict']) {
            Session::flash('erro', 'Este rascunho foi alterado em outra janela depois que você o abriu. Suas edições foram salvas, mas o plano não foi publicado; confira o texto e publique de novo.');
        } else {
            Session::flash('ok', 'Rascunho salvo.');
        }
        return Response::redirect('/planos/' . $plan['id'] . '/editar');
    }

    /** POST /planos/previa */
    public function preview(Request $r): Response
    {
        $user = $r->attributes['user'];
        Staff::assert($user, $r);
        Container::gate()->authorize($user, 'edit', Policy::NUTRICAO, null, $r);
        return Response::json(['html' => PlanText::toHtml(self::clean($r->post['texto'] ?? ''))]);
    }

    public function publish(Request $r): Response
    {
        return $this->transition($r, function (array $plan, int $by) use ($r): Response {
            Container::mealPlans()->publish((int) $plan['id'], $by, $r);
            Session::flash('ok', 'Plano publicado.');
            return Response::redirect('/pacientes/' . $plan['patient_id'] . '/plano');
        }, fn(array $plan): string => '/planos/' . $plan['id']);
    }

    public function end(Request $r): Response
    {
        $plan = $this->authPlan($r, 'edit');
        $user = $r->attributes['user'];
        $reason = $r->post['motivo'] ?? '';
        $reason = is_string($reason) ? trim(mb_scrub($reason, 'UTF-8')) : '';
        try {
            Container::mealPlans()->end((int) $plan['id'], $reason, (int) $user['id'], $r);
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            return $this->renderShow($r, $plan, ['endError' => $e->getMessage(), 'motivo' => $reason]);
        }
        Session::flash('ok', 'Plano encerrado.');
        return Response::redirect('/pacientes/' . $plan['patient_id'] . '/plano');
    }

    public function discard(Request $r): Response
    {
        return $this->transition($r, function (array $plan, int $by) use ($r): Response {
            Container::mealPlans()->discard((int) $plan['id'], $by, $r);
            Session::flash('ok', 'Rascunho descartado.');
            return Response::redirect('/pacientes/' . $plan['patient_id'] . '/plano');
        }, fn(array $plan): string => '/pacientes/' . $plan['patient_id'] . '/plano');
    }

    public function newDraft(Request $r): Response
    {
        return $this->transition($r, function (array $plan, int $by) use ($r): Response {
            $id = Container::mealPlans()->draftFrom((int) $plan['id'], $by, $r);
            return Response::redirect('/planos/' . $id . '/editar');
        }, fn(array $plan): string => '/pacientes/' . $plan['patient_id'] . '/plano');
    }

    /** Executa uma transicao do repositorio; erros de estado viram flash e voltam para $back. */
    private function transition(Request $r, callable $do, callable $back): Response
    {
        $plan = $this->authPlan($r, 'edit');
        try {
            return $do($plan, (int) $r->attributes['user']['id']);
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
            return Response::redirect($back($plan));
        }
    }

    /** Texto do usuario: so string, UTF-8 valido, normalizado. */
    private static function clean(mixed $v): string
    {
        return is_string($v) ? PlanText::normalizePaste(mb_scrub($v, 'UTF-8')) : '';
    }

    private function authPatient(Request $r, string $action): int
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Staff::assert($user, $r, $id);
        Container::gate()->authorize($user, $action, Policy::NUTRICAO, $id, $r);
        $this->patient($id);
        return $id;
    }

    private function authPlan(Request $r, string $action): array
    {
        $plan = Container::mealPlans()->find((int) $r->params['planId']);
        if ($plan === null) {
            throw new HttpException(404);
        }
        $user = $r->attributes['user'];
        Staff::assert($user, $r, (int) $plan['patient_id']);
        Container::gate()->authorize($user, $action, Policy::NUTRICAO, (int) $plan['patient_id'], $r);
        if ($plan['status'] === 'rascunho' && $plan['discarded_at'] !== null) {
            throw new HttpException(404);
        }
        return $plan;
    }

    private function patient(int $id): array
    {
        $p = Container::patients()->find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }

    private function renderIndex(Request $r, int $id, array $extra = []): Response
    {
        $user = $r->attributes['user'];
        $repo = Container::mealPlans();
        Container::audit()->record((int) $user['id'], 'view', 'patients', $id, $id, null, ['section' => 'nutricao'], $r);
        return $this->page('Nutrition/views/index.php', 'Plano alimentar', $r, $extra + [
            'patient' => $this->patient($id),
            'current' => $repo->current($id),
            'draft' => $repo->draft($id),
            'history' => $repo->history($id),
            'templates' => Container::db()->all('SELECT id, title, mode FROM meal_plan_templates WHERE deleted_at IS NULL ORDER BY title'),
            'canEdit' => Container::gate()->check($user, 'edit', Policy::NUTRICAO, $id),
            'askDraft' => null,
            'posted' => ['modo' => 'texto', 'origem' => 'branco', 'modelo_id' => 0],
        ]);
    }

    private function renderShow(Request $r, array $plan, array $extra = []): Response
    {
        $user = $r->attributes['user'];
        $pid = (int) $plan['patient_id'];
        return $this->page('Nutrition/views/show.php', (string) $plan['title'], $r, $extra + [
            'plan' => $plan,
            'patient' => $this->patient($pid),
            'canEdit' => Container::gate()->check($user, 'edit', Policy::NUTRICAO, $pid),
            'endError' => null,
            'motivo' => '',
        ]);
    }

    private function renderEditor(Request $r, array $plan, array $values, array $errors): Response
    {
        $pid = (int) $plan['patient_id'];
        $tpl = $plan['mode'] === 'texto' ? 'Nutrition/views/edit_text.php' : 'Nutrition/views/edit_structured.php';
        return $this->page($tpl, 'Editar plano alimentar', $r, [
            'plan' => $plan,
            'patient' => $this->patient($pid),
            'values' => $values,
            'errors' => $errors,
            'context' => [
                'kind' => 'plan',
                'saveUrl' => '/planos/' . $plan['id'] . '/editar',
                'backUrl' => '/pacientes/' . $pid . '/plano',
            ],
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
