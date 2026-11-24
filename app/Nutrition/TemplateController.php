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

/** Modelos de plano alimentar e trechos de texto, compartilhados pela equipe. */
final class TemplateController
{
    // ---- Modelos -----------------------------------------------------------------------------------------------

    /** GET /modelos-plano */
    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        Staff::assert($user, $r);
        return $this->page('Nutrition/views/templates_index.php', 'Modelos de plano', $r, [
            'templates' => Container::mealPlanTemplates()->all(),
            'canEdit' => Container::gate()->check($user, 'edit', Policy::NUTRICAO, null),
            'me' => $user,
        ], '/modelos-plano');
    }

    /** GET /modelos-plano/novo?modo= */
    public function createForm(Request $r): Response
    {
        $this->authWrite($r);
        $mode = ($r->query['modo'] ?? '') === 'estruturado' ? 'estruturado' : 'texto';
        return $this->page('Nutrition/views/template_form.php', 'Novo modelo', $r, ['title_value' => '', 'mode' => $mode, 'errors' => []], '/modelos-plano');
    }

    /** POST /modelos-plano/novo */
    public function create(Request $r): Response
    {
        $user = $this->authWrite($r);
        $mode = ($r->post['modo'] ?? '') === 'estruturado' ? 'estruturado' : 'texto';
        $title = self::str($r->post['titulo'] ?? null);
        try {
            $id = Container::mealPlanTemplates()->create([
                'title' => $title, 'mode' => $mode, 'body_text' => null, 'guidance_text' => null,
                'structure' => $mode === 'estruturado' ? MealPlanController::suggestedStructure() : null,
            ], (int) $user['id'], $r);
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            return $this->page('Nutrition/views/template_form.php', 'Novo modelo', $r, ['title_value' => $title, 'mode' => $mode, 'errors' => [$e->getMessage()]], '/modelos-plano');
        }
        return Response::redirect('/modelos-plano/' . $id . '/editar');
    }

    /** GET /modelos-plano/{templateId}/editar */
    public function edit(Request $r): Response
    {
        $this->authWrite($r);
        $tpl = $this->ownTemplate($r);
        return $this->renderEditor($r, $tpl, [
            'title' => (string) $tpl['title'], 'corpo' => (string) ($tpl['body_text'] ?? ''),
            'orientacoes' => (string) ($tpl['guidance_text'] ?? ''), 'revisao' => 1,
        ], []);
    }

    /** POST /modelos-plano/{templateId}/editar (texto: formulario com redirect; estruturado: JSON para o editor) */
    public function update(Request $r): Response
    {
        $user = $this->authWrite($r);
        $tpl = $this->ownTemplate($r);
        $repo = Container::mealPlanTemplates();
        if ($tpl['mode'] === 'estruturado') {
            [$title, $guidance, $structure, $errors] = MealPlanController::readStructuredPost($r->post, 'do modelo');
            if ($errors !== [] || $structure === null) {
                return Response::json(['ok' => false, 'errors' => $errors], 422);
            }
            try {
                $repo->update((int) $tpl['id'], [
                    'title' => $title, 'mode' => 'estruturado', 'body_text' => null, 'guidance_text' => $guidance, 'structure' => $structure,
                ], $user, $r, ($r->post['auto'] ?? '') === '1');
            } catch (\PDOException $e) {
                throw $e; // erro de banco nunca vai para a tela
            } catch (\RuntimeException $e) {
                return Response::json(['ok' => false, 'errors' => [$e->getMessage()]], 409);
            }
            // Modelo nao tem controle de revisao: revisao constante, nunca ha conflito.
            return Response::json(['ok' => true, 'revision' => 1, 'conflict' => false] + MealPlanController::totalsPayload($structure));
        }

        $post = $r->post;
        $errors = [];
        foreach (['corpo' => 'O texto do modelo', 'orientacoes' => 'As orientações'] as $k => $label) {
            if (isset($post[$k]) && !is_string($post[$k])) {
                $errors[] = $label . ' é inválido.';
            }
        }
        $values = [
            'title' => self::str($post['titulo'] ?? null), 'corpo' => MealPlanController::clean($post['corpo'] ?? ''),
            'orientacoes' => MealPlanController::clean($post['orientacoes'] ?? ''), 'revisao' => 1,
        ];
        if ($errors === []) {
            try {
                $repo->update((int) $tpl['id'], [
                    'title' => $values['title'], 'mode' => 'texto', 'body_text' => $values['corpo'],
                    'guidance_text' => $values['orientacoes'], 'structure' => null,
                ], $user, $r);
                Session::flash('ok', 'Modelo salvo.');
                return Response::redirect('/modelos-plano/' . $tpl['id'] . '/editar');
            } catch (\PDOException $e) {
                throw $e; // erro de banco nunca vai para a tela
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return $this->renderEditor($r, $tpl, $values, $errors);
    }

    /** POST /modelos-plano/{templateId}/excluir */
    public function delete(Request $r): Response
    {
        $user = $this->authWrite($r);
        $tpl = $this->ownTemplate($r);
        try {
            Container::mealPlanTemplates()->delete((int) $tpl['id'], $user, $r);
            Session::flash('ok', 'Modelo excluído.');
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
        }
        return Response::redirect('/modelos-plano');
    }

    /** POST /planos/{planId}/salvar-como-modelo */
    public function saveFromPlan(Request $r): Response
    {
        $user = $r->attributes['user'];
        $plan = Container::mealPlans()->find((int) $r->params['planId']);
        if ($plan === null || ($plan['status'] === 'rascunho' && $plan['discarded_at'] !== null)) {
            throw new HttpException(404);
        }
        Staff::assert($user, $r, (int) $plan['patient_id']);
        Container::gate()->authorize($user, 'edit', Policy::NUTRICAO, (int) $plan['patient_id'], $r);
        $dia = $r->post['dia'] ?? null;
        $diaIdx = is_string($dia) && preg_match('/^\d{1,3}$/', $dia) === 1 ? (int) $dia : null;
        if ($plan['mode'] === 'estruturado' && \App\Nutrition\PlanDays::isMulti((array) $plan['structure'])
            && ($diaIdx === null || $diaIdx >= count((array) ($plan['structure']['days'] ?? [])))) {
            Session::flash('erro', 'Escolha o dia para salvar como modelo.');
            return Response::redirect('/planos/' . $plan['id']);
        }
        try {
            Container::mealPlanTemplates()->fromPlan((int) $plan['id'], self::str($r->post['titulo'] ?? null), (int) $user['id'], $r, $diaIdx);
            Session::flash('ok', 'Modelo criado a partir do plano. Ele não leva dados do paciente.');
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
            return Response::redirect('/planos/' . $plan['id']);
        }
        return Response::redirect('/modelos-plano');
    }

    // ---- Trechos -----------------------------------------------------------------------------------------------

    /** GET /trechos */
    public function snippets(Request $r): Response
    {
        $user = $r->attributes['user'];
        Staff::assert($user, $r);
        return $this->page('Nutrition/views/snippets_index.php', 'Trechos de texto', $r, [
            'snippets' => Container::textSnippets()->all(),
            'canEdit' => Container::gate()->check($user, 'edit', Policy::NUTRICAO, null),
            'me' => $user,
        ], '/modelos-plano');
    }

    /** GET /trechos.json */
    public function snippetsJson(Request $r): Response
    {
        Staff::assert($r->attributes['user'], $r);
        $items = [];
        foreach (Container::textSnippets()->all() as $s) {
            $items[] = ['id' => (int) $s['id'], 'title' => (string) $s['title'], 'body' => (string) $s['body']];
        }
        return Response::json(['items' => $items]);
    }

    /** GET /trechos/novo */
    public function snippetCreateForm(Request $r): Response
    {
        $this->authWrite($r);
        return $this->snippetForm($r, null, ['title' => '', 'body' => ''], []);
    }

    /** POST /trechos/novo */
    public function snippetCreate(Request $r): Response
    {
        $user = $this->authWrite($r);
        $values = ['title' => self::str($r->post['titulo'] ?? null), 'body' => self::str($r->post['corpo'] ?? null)];
        try {
            Container::textSnippets()->create($values['title'], $values['body'], (int) $user['id'], $r);
            Session::flash('ok', 'Trecho cadastrado.');
            return Response::redirect('/trechos');
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            return $this->snippetForm($r, null, $values, [$e->getMessage()]);
        }
    }

    /** GET /trechos/{snippetId}/editar */
    public function snippetEditForm(Request $r): Response
    {
        $this->authWrite($r);
        $s = $this->ownSnippet($r);
        return $this->snippetForm($r, $s, ['title' => (string) $s['title'], 'body' => (string) $s['body']], []);
    }

    /** POST /trechos/{snippetId}/editar */
    public function snippetUpdate(Request $r): Response
    {
        $user = $this->authWrite($r);
        $s = $this->ownSnippet($r);
        $values = ['title' => self::str($r->post['titulo'] ?? null), 'body' => self::str($r->post['corpo'] ?? null)];
        try {
            Container::textSnippets()->update((int) $s['id'], $values['title'], $values['body'], $user, $r);
            Session::flash('ok', 'Trecho salvo.');
            return Response::redirect('/trechos');
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            return $this->snippetForm($r, $s, $values, [$e->getMessage()]);
        }
    }

    /** POST /trechos/{snippetId}/excluir */
    public function snippetDelete(Request $r): Response
    {
        $user = $this->authWrite($r);
        $s = $this->ownSnippet($r);
        try {
            Container::textSnippets()->delete((int) $s['id'], $user, $r);
            Session::flash('ok', 'Trecho excluído.');
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
        }
        return Response::redirect('/trechos');
    }

    // ---- Apoio -------------------------------------------------------------------------------------------------

    private function authWrite(Request $r): array
    {
        $user = $r->attributes['user'];
        Staff::assert($user, $r);
        Container::gate()->authorize($user, 'edit', Policy::NUTRICAO, null, $r);
        return $user;
    }

    /** Modelo existente e editavel por quem esta logado (autor ou administradora); senao 404/403. */
    private function ownTemplate(Request $r): array
    {
        $tpl = Container::mealPlanTemplates()->find((int) $r->params['templateId']);
        if ($tpl === null) {
            throw new HttpException(404, 'Modelo não encontrado');
        }
        if (!TemplateRepository::canModify($tpl, $r->attributes['user'])) {
            throw new HttpException(403, 'Acesso negado');
        }
        return $tpl;
    }

    private function ownSnippet(Request $r): array
    {
        $s = Container::textSnippets()->find((int) $r->params['snippetId']);
        if ($s === null) {
            throw new HttpException(404, 'Trecho não encontrado');
        }
        if (!SnippetRepository::canModify($s, $r->attributes['user'])) {
            throw new HttpException(403, 'Acesso negado');
        }
        return $s;
    }

    private function renderEditor(Request $r, array $tpl, array $values, array $errors): Response
    {
        $view = $tpl['mode'] === 'texto' ? 'Nutrition/views/edit_text.php' : 'Nutrition/views/edit_structured.php';
        return $this->page($view, 'Editar modelo', $r, [
            'plan' => $tpl + ['revision' => 1],
            'patient' => null,
            'metaKcal' => null,
            'values' => $values,
            'errors' => $errors,
            'context' => [
                'kind' => 'template',
                'saveUrl' => '/modelos-plano/' . $tpl['id'] . '/editar',
                'backUrl' => '/modelos-plano',
            ],
        ], '/modelos-plano');
    }

    private function snippetForm(Request $r, ?array $snippet, array $values, array $errors): Response
    {
        return $this->page('Nutrition/views/snippet_form.php', $snippet === null ? 'Novo trecho' : 'Editar trecho', $r, [
            'snippet' => $snippet, 'values' => $values, 'errors' => $errors,
        ], '/modelos-plano');
    }

    private function page(string $template, string $title, Request $r, array $data, string $active): Response
    {
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $r->attributes['user']['name'], 'active' => $active,
        ], 'Layout/views/layout.php'));
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? trim(mb_scrub($v, 'UTF-8')) : '';
    }
}
