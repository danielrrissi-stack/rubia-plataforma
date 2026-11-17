<?php
declare(strict_types=1);

namespace App\Goals;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Metas da equipe: biblioteca de modelos e metas do paciente. Medica faz tudo; nutricionista so metas/modelos de
 * categorias nutricionais de pacientes liberados a ela e nunca muda a categoria. Familia e crianca usam o portal
 * (403 aqui). Ordem: 404 -> papel/Gate -> regra de categoria. A equipe nunca marca metas.
 */
final class GoalController
{
    private const STAFF = ['medico', 'nutricionista'];

    // ---- biblioteca de modelos ----

    /** GET /metas-modelo */
    public function templates(Request $r): Response
    {
        $user = $this->staff($r, null);
        $list = array_values(array_filter(
            Container::goals()->templates(false),
            fn (array $t): bool => $this->mayTouchCategory($user, (string) $t['category'])
        ));
        return $this->page('Goals/views/library_index.php', 'Biblioteca de metas', $r, '/metas-modelo', ['templates' => $list]);
    }

    /** GET /metas-modelo/novo */
    public function templateCreateForm(Request $r): Response
    {
        $user = $this->staff($r, null);
        return $this->templateForm($r, $user, null, ['family_text' => '', 'child_text' => '', 'emoji' => '', 'category' => $this->categories($user)[0], 'times_per_week' => '7', 'active' => 1, 'sort_order' => '0'], []);
    }

    /** POST /metas-modelo/novo */
    public function templateCreate(Request $r): Response
    {
        $user = $this->staff($r, null);
        $data = $this->templateInput($r);
        if (!$this->mayTouchCategory($user, (string) $data['category'])) {
            $this->deny($user, $r, null);
        }
        try {
            Container::goals()->saveTemplate(null, $data, (int) $user['id'], $r);
        } catch (InvalidGoalException $e) {
            return $this->templateForm($r, $user, null, $data, $e->errors);
        }
        Session::flash('ok', 'Meta pronta cadastrada.');
        return Response::redirect('/metas-modelo');
    }

    /** GET /metas-modelo/{goalTemplateId}/editar */
    public function templateEditForm(Request $r): Response
    {
        $t = $this->findTemplate($r);
        $user = $this->staff($r, null);
        $this->assertTemplateCategory($user, $r, $t);
        return $this->templateForm($r, $user, $t, $t, []);
    }

    /** POST /metas-modelo/{goalTemplateId}/editar */
    public function templateUpdate(Request $r): Response
    {
        $t = $this->findTemplate($r);
        $user = $this->staff($r, null);
        $this->assertTemplateCategory($user, $r, $t);
        $data = $this->templateInput($r);
        if ($user['role'] === 'nutricionista') {
            $data['category'] = $t['category']; // so a medica muda a categoria
        }
        try {
            Container::goals()->saveTemplate((int) $t['id'], $data, (int) $user['id'], $r);
        } catch (InvalidGoalException $e) {
            return $this->templateForm($r, $user, $t, $data, $e->errors);
        }
        Session::flash('ok', 'Meta pronta salva.');
        return Response::redirect('/metas-modelo');
    }

    // ---- metas do paciente ----

    /** GET /pacientes/{id}/metas */
    public function index(Request $r): Response
    {
        $pid = (int) $r->params['id'];
        $patient = $this->patient($pid);
        $user = $this->staff($r, $pid);
        $this->authorizePatient($user, $r, $pid, 'view');
        Container::audit()->record((int) $user['id'], 'view', 'patient_goals', null, $pid, null, ['section' => 'metas'], $r);

        $today = date('Y-m-d');
        $all = Container::goals()->allForViewer($pid, $user);
        $active = [];
        $ended = [];
        foreach ($all['goals'] as $g) {
            if (GoalPanel::isActive($g, $today)) {
                $active[] = $g;
            } else {
                $ended[] = $g;
            }
        }
        return $this->page('Goals/views/index.php', 'Metas', $r, '/pacientes', [
            'patient' => $patient,
            'today' => $today,
            'activePanel' => GoalPanel::build($active, $all['checks'], $today, 8),
            'endedPanel' => GoalPanel::build($ended, $all['checks'], $today, 8),
            'stars' => $user['role'] === 'medico' ? GoalCalc::stars($all['goals'], $all['checks']) : null,
        ]);
    }

    /** GET /pacientes/{id}/metas/nova */
    public function createForm(Request $r): Response
    {
        $pid = (int) $r->params['id'];
        $patient = $this->patient($pid);
        $user = $this->staff($r, $pid);
        $this->authorizePatient($user, $r, $pid, 'edit');
        $values = ['family_text' => '', 'child_text' => '', 'emoji' => '', 'category' => $this->categories($user)[0],
            'times_per_week' => '7', 'start_date' => date('Y-m-d'), 'end_date' => '', 'secret' => 0, 'template_id' => ''];
        $tid = (string) $r->input('modelo', '');
        if ($tid !== '' && ctype_digit($tid)) {
            $t = Container::goals()->findTemplate((int) $tid);
            if ($t !== null && (int) $t['active'] === 1 && $this->mayTouchCategory($user, (string) $t['category'])) {
                $values = [
                    'family_text' => $t['family_text'], 'child_text' => (string) $t['child_text'], 'emoji' => $t['emoji'],
                    'category' => $t['category'], 'times_per_week' => (string) $t['times_per_week'], 'template_id' => (string) $t['id'],
                ] + $values;
            }
        }
        return $this->goalForm($r, $user, $patient, null, $values, []);
    }

    /** POST /pacientes/{id}/metas/nova */
    public function create(Request $r): Response
    {
        $pid = (int) $r->params['id'];
        $patient = $this->patient($pid);
        $user = $this->staff($r, $pid);
        $this->authorizePatient($user, $r, $pid, 'edit');
        $data = $this->goalInput($r);
        $data['template_id'] = $this->usableTemplateId($user, (string) $r->input('template_id', ''));
        if (!$this->mayTouchCategory($user, (string) $data['category'])) {
            $this->deny($user, $r, $pid);
        }
        if ($user['role'] === 'nutricionista') {
            $data['secret'] = 0;
        }
        try {
            Container::goals()->create($pid, $data, (int) $user['id'], $r);
        } catch (InvalidGoalException $e) {
            return $this->goalForm($r, $user, $patient, null, $data, $e->errors);
        }
        Session::flash('ok', 'Meta cadastrada.');
        return Response::redirect('/pacientes/' . $pid . '/metas');
    }

    /** GET /metas/{goalId}/editar */
    public function editForm(Request $r): Response
    {
        [$user, $goal, $patient] = $this->openGoal($r);
        return $this->goalForm($r, $user, $patient, $goal, $goal, []);
    }

    /** POST /metas/{goalId}/editar */
    public function update(Request $r): Response
    {
        [$user, $goal, $patient] = $this->openGoal($r);
        $data = $this->goalInput($r);
        $data['template_id'] = $goal['template_id'];
        if ($user['role'] === 'nutricionista') {
            $data['category'] = $goal['category']; // so a medica muda a categoria
            $data['secret'] = $goal['secret'];
        }
        try {
            Container::goals()->update((int) $goal['id'], $data, (int) $user['id'], $r);
        } catch (InvalidGoalException $e) {
            return $this->goalForm($r, $user, $patient, $goal, $data, $e->errors);
        }
        Session::flash('ok', 'Meta salva.');
        return Response::redirect('/pacientes/' . (int) $goal['patient_id'] . '/metas');
    }

    /** POST /metas/{goalId}/encerrar */
    public function end(Request $r): Response
    {
        [$user, $goal] = $this->openGoal($r);
        try {
            Container::goals()->end((int) $goal['id'], date('Y-m-d'), (int) $user['id'], $r);
            Session::flash('ok', 'Meta encerrada.');
        } catch (InvalidGoalException $e) {
            Session::flash('erro', $e->errors[0] ?? 'Não foi possível encerrar a meta.');
        }
        return Response::redirect('/pacientes/' . (int) $goal['patient_id'] . '/metas');
    }

    /** POST /metas/{goalId}/excluir */
    public function delete(Request $r): Response
    {
        [$user, $goal] = $this->openGoal($r);
        Container::goals()->delete((int) $goal['id'], (int) $user['id'], $r);
        Session::flash('ok', 'Meta excluída.');
        return Response::redirect('/pacientes/' . (int) $goal['patient_id'] . '/metas');
    }

    // ---- ficha do paciente ----

    /**
     * Cartao Metas da ficha: so o que o usuario pode ver; null se nao e equipe ou nao alcanca o paciente.
     *
     * @return ?array{panel: array, stars: ?int, lastCheck: ?string}
     */
    public static function card(array $user, int $patientId, string $today): ?array
    {
        if (!in_array($user['role'], self::STAFF, true) || !self::allowed($user, 'view', $patientId)) {
            return null;
        }
        $all = Container::goals()->allForViewer($patientId, $user);
        $active = array_values(array_filter($all['goals'], fn (array $g): bool => GoalPanel::isActive($g, $today)));
        $last = null;
        foreach ($all['checks'] as $c) {
            $last = $last === null || $c['day'] > $last ? $c['day'] : $last;
        }
        $doctor = $user['role'] === 'medico';
        return [
            'panel' => GoalPanel::build($active, $all['checks'], $today, 3),
            'stars' => $doctor ? GoalCalc::stars($all['goals'], $all['checks']) : null,
            'lastCheck' => $doctor ? $last : null,
        ];
    }

    // ---- helpers ----

    /** Medica: secao METAS; nutricionista nao tem METAS na Policy, entao vale o acesso de NUTRICAO ao paciente liberado. */
    private static function allowed(array $user, string $action, int $pid): bool
    {
        $section = $user['role'] === 'medico' ? Policy::METAS : Policy::NUTRICAO;
        return Container::gate()->check($user, $action, $section, $pid);
    }

    /** Papel da equipe (antes do Gate: a familia ganha 'view METAS' para o portal). */
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
            isset($user['id']) ? (int) $user['id'] : null, 'denied', 'patient_goals', null, $pid, null,
            ['acao' => 'equipe', 'section' => 'metas'], $r,
        );
        throw new HttpException(403, 'Acesso negado');
    }

    private function mayTouchCategory(array $user, string $category): bool
    {
        return $user['role'] === 'medico' || in_array($category, GoalRepository::NUTRITIONAL, true);
    }

    /** @return list<string> categorias que o usuario pode escolher ao criar */
    private function categories(array $user): array
    {
        return $user['role'] === 'medico' ? GoalRepository::CATEGORIES : GoalRepository::NUTRITIONAL;
    }

    private function patient(int $pid): array
    {
        $p = Container::patients()->find($pid);
        if ($p === null || $p['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        return $p;
    }

    /** @return array{0:array,1:array,2:array} usuario, meta e paciente (404 -> papel/Gate -> categoria) */
    private function openGoal(Request $r): array
    {
        $goal = Container::goals()->find((int) $r->params['goalId']);
        if ($goal === null || $goal['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        $pid = (int) $goal['patient_id'];
        $patient = $this->patient($pid);
        $user = $this->staff($r, $pid);
        $this->authorizePatient($user, $r, $pid, 'edit');
        if (!$this->mayTouchCategory($user, (string) $goal['category'])) {
            $this->deny($user, $r, $pid);
        }
        return [$user, $goal, $patient];
    }

    /** template_id vindo do POST so vale se o modelo existe, esta ativo e a categoria e permitida ao usuario; senao e ignorado. */
    private function usableTemplateId(array $user, string $raw): string
    {
        if ($raw === '' || !ctype_digit($raw)) {
            return '';
        }
        $t = Container::goals()->findTemplate((int) $raw);
        return $t !== null && (int) $t['active'] === 1 && $this->mayTouchCategory($user, (string) $t['category']) ? $raw : '';
    }

    private function findTemplate(Request $r): array
    {
        $t = Container::goals()->findTemplate((int) $r->params['goalTemplateId']);
        if ($t === null) {
            throw new HttpException(404);
        }
        return $t;
    }

    private function assertTemplateCategory(array $user, Request $r, array $t): void
    {
        if (!$this->mayTouchCategory($user, (string) $t['category'])) {
            $this->deny($user, $r, null);
        }
    }

    private function templateInput(Request $r): array
    {
        return [
            'family_text' => (string) $r->input('family_text', ''),
            'child_text' => (string) $r->input('child_text', ''),
            'emoji' => (string) $r->input('emoji', ''),
            'category' => (string) $r->input('category', ''),
            'times_per_week' => (string) $r->input('times_per_week', ''),
            'sort_order' => (string) $r->input('sort_order', '0'),
            'active' => (string) $r->input('active', '') === '1' ? 1 : 0,
        ];
    }

    private function goalInput(Request $r): array
    {
        return [
            'family_text' => (string) $r->input('family_text', ''),
            'child_text' => (string) $r->input('child_text', ''),
            'emoji' => (string) $r->input('emoji', ''),
            'category' => (string) $r->input('category', ''),
            'times_per_week' => (string) $r->input('times_per_week', ''),
            'start_date' => (string) $r->input('start_date', ''),
            'end_date' => (string) $r->input('end_date', ''),
            'secret' => (string) $r->input('secret', '') === '1' ? 1 : 0,
        ];
    }

    private function templateForm(Request $r, array $user, ?array $template, array $values, array $errors): Response
    {
        return $this->page('Goals/views/library_form.php', $template === null ? 'Nova meta pronta' : 'Editar meta pronta', $r, '/metas-modelo', [
            'template' => $template, 'values' => $values, 'errors' => $errors,
            'categories' => $this->categories($user), 'lockCategory' => $template !== null && $user['role'] !== 'medico',
        ]);
    }

    private function goalForm(Request $r, array $user, array $patient, ?array $goal, array $values, array $errors): Response
    {
        $templates = array_values(array_filter(
            Container::goals()->templates(true),
            fn (array $t): bool => $this->mayTouchCategory($user, (string) $t['category'])
        ));
        return $this->page('Goals/views/form.php', $goal === null ? 'Nova meta' : 'Editar meta', $r, '/pacientes', [
            'patient' => $patient, 'goal' => $goal, 'values' => $values, 'errors' => $errors,
            'templates' => $templates,
            'categories' => $this->categories($user),
            'lockCategory' => $goal !== null && $user['role'] !== 'medico',
            'canSecret' => $user['role'] === 'medico' && $patient['child_user_id'] !== null,
        ]);
    }

    private function page(string $template, string $title, Request $r, string $active, array $data): Response
    {
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $r->attributes['user']['name'], 'active' => $active,
        ], 'Layout/views/layout.php'));
    }
}
