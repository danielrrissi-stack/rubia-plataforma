<?php
declare(strict_types=1);

namespace App\Diets;

use App\Access\Policy;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Growth\Age;
use App\Nutrition\InvalidPlanException;
use App\Nutrition\MealPlanCalculator;
use App\Nutrition\MealPlanResolver;
use App\Nutrition\Staff;

/** Dietas prontas: consulta (equipe), "Sugestao da semana" no paciente e restricoes alimentares. */
final class DietController
{
    public const BAND_LABEL = ['infantil' => 'Infantil (3 a 10 anos)', 'adulto' => 'Adolescente/adulto (11 anos ou mais)'];

    /** GET /dietas-prontas?versao=&semana=&kcal= */
    public function index(Request $r): Response
    {
        $user = $r->attributes['user'];
        Staff::assert($user, $r);
        $band = isset(DietBuilder::BANDS[$r->query['versao'] ?? '']) ? (string) $r->query['versao'] : 'adulto';
        $week = self::int($r->query['semana'] ?? null, 1, 52) ?? DietBuilder::weekOf(date('Y-m-d'));
        $kcal = self::int($r->query['kcal'] ?? null, 1000, 3000);
        return $this->page('Diets/views/index.php', 'Dietas prontas', $r, [
            'rows' => Container::dietLibrary()->search($band, $week, $kcal),
            'total' => Container::dietLibrary()->count(),
            'band' => $band, 'week' => $week, 'kcal' => $kcal,
        ], '/modelos-plano');
    }

    /** GET /dietas-prontas/{code} */
    public function show(Request $r): Response
    {
        Staff::assert($r->attributes['user'], $r);
        $diet = Container::dietLibrary()->findByCode((string) $r->params['code']);
        if ($diet === null) {
            throw new HttpException(404, 'Dieta não encontrada');
        }
        try {
            $structure = (new MealPlanResolver(Container::foods()))->resolve($diet['structure'], true);
        } catch (InvalidPlanException $e) {
            throw new \RuntimeException('Dieta pronta inválida: ' . implode(' ', $e->errors));
        }
        return $this->page('Diets/views/show.php', (string) $diet['code'], $r, [
            'diet' => $diet, 'structure' => $structure, 'day' => MealPlanCalculator::day($structure),
            'percents' => MealPlanCalculator::macroPercents(MealPlanCalculator::day($structure)),
        ], '/modelos-plano');
    }

    /** POST /pacientes/{id}/plano/sugestao */
    public function suggest(Request $r): Response
    {
        $user = $r->attributes['user'];
        $pid = $this->authPatient($r, 'edit');
        $back = Response::redirect('/pacientes/' . $pid . '/plano');
        $patient = Container::patients()->find($pid) ?? throw new HttpException(404);
        $auto = DietBuilder::bandForAge(Age::years((string) $patient['birth_date'], date('Y-m-d')));
        if ($auto === null) {
            Session::flash('erro', 'As dietas prontas são para pacientes a partir de 3 anos.');
            return $back;
        }
        $band = isset(DietBuilder::BANDS[$r->post['versao'] ?? '']) ? (string) $r->post['versao'] : $auto;
        $kcal = self::int($r->post['kcal'] ?? null, 0, 9999);
        $week = self::int($r->post['semana'] ?? null, 1, 52);
        if ($kcal === null || !in_array($kcal, DietBuilder::levels($band), true) || $week === null) {
            Session::flash('erro', 'Escolha as calorias e a semana da sugestão.');
            return $back;
        }
        $diet = Container::dietLibrary()->pick($band, $week, $kcal);
        if ($diet === null) {
            Session::flash('erro', 'O banco de dietas prontas não está carregado. Peça para aplicar os dados de referência (tools/migrate.php).');
            return $back;
        }

        $restr = Container::foodRestrictions()->get($pid);
        $adapted = $this->adapter($diet['structure'])->adapt($diet['structure'], $restr['items'], $restr['other_text']);
        try {
            $structure = (new MealPlanResolver(Container::foods()))->resolve($adapted['structure'], true);
        } catch (InvalidPlanException $e) {
            Session::flash('erro', 'Não foi possível usar esta dieta pronta: ' . implode(' ', $e->errors));
            return $back;
        }

        $repo = Container::mealPlans();
        $draft = $repo->draft($pid);
        if ($draft !== null) {
            if (($r->post['descartar_rascunho'] ?? '') !== '1') {
                Session::flash('erro', 'Este paciente já tem um rascunho. Continue o rascunho ou marque "descartar o rascunho atual" para gerar a sugestão.');
                return $back;
            }
            $repo->discard((int) $draft['id'], (int) $user['id'], $r);
        }
        try {
            $planId = $repo->createDraft($pid, 'estruturado', [
                'title' => (string) $diet['title'], 'body_text' => null,
                'guidance_text' => (string) $diet['guidance_text'], 'structure' => $structure,
            ], (int) $user['id'], $r, null, null, (string) $diet['code']);
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
            return $back;
        }
        $msg = 'Sugestão ' . $diet['code'] . ' criada como rascunho. Avalie, ajuste e publique.';
        if ($adapted['changes'] !== []) {
            $msg .= ' Adaptações pelas restrições: ' . implode(' ', $adapted['changes']);
        }
        Session::flash('ok', $msg);
        if ($adapted['warnings'] !== []) {
            Session::flash('erro', 'Atenção: ' . implode(' ', $adapted['warnings']));
        }
        return Response::redirect('/planos/' . $planId . '/editar');
    }

    /** POST /pacientes/{id}/restricoes */
    public function saveRestrictions(Request $r): Response
    {
        $user = $r->attributes['user'];
        $pid = $this->authPatient($r, 'edit');
        $items = $r->post['restricoes'] ?? [];
        $items = is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        $other = is_string($r->post['outros'] ?? null) ? $r->post['outros'] : '';
        Container::foodRestrictions()->save($pid, $items, $other, (int) $user['id'], $r);
        Session::flash('ok', 'Restrições alimentares salvas.');
        return Response::redirect('/pacientes/' . $pid . '/plano');
    }

    /**
     * Dados do cartao "Sugestao da semana" + "Restricoes" na tela do plano (usado pelo MealPlanController).
     * @return array{band:?string,kcal:?int,week:int,levels:array,restrictions:array,allergyText:?string,goalKcal:?int}
     */
    public static function patientCard(array $patient, array $user): array
    {
        $pid = (int) $patient['id'];
        $band = DietBuilder::bandForAge(Age::years((string) $patient['birth_date'], date('Y-m-d')));
        $goal = Container::energyGoals()->current($pid);
        $goalKcal = $goal === null ? null : (int) $goal['get_kcal'];
        $kcal = null;
        if ($band !== null) {
            [$lo, $hi] = DietBuilder::BANDS[$band];
            $kcal = $goalKcal === null ? $lo + 500 : (int) max($lo, min($hi, round($goalKcal / 100) * 100));
        }
        $allergy = null;
        if (Container::gate()->check($user, 'view', Policy::HISTORICO, $pid)) {
            $texts = [];
            foreach (Container::clinicalEntries()->currentByKind($pid, 'alergias') as $e) {
                $p = json_decode((string) $e['payload'], true);
                $t = trim((string) ($p['texto'] ?? ''));
                if ($t !== '') {
                    $texts[] = $t;
                }
            }
            $allergy = $texts === [] ? '' : implode("\n", $texts);
        }
        return [
            'band' => $band, 'kcal' => $kcal, 'week' => DietBuilder::weekOf(date('Y-m-d')), 'goalKcal' => $goalKcal,
            'levels' => ['infantil' => DietBuilder::levels('infantil'), 'adulto' => DietBuilder::levels('adulto')],
            'restrictions' => Container::foodRestrictions()->get($pid), 'allergyText' => $allergy,
        ];
    }

    private function adapter(array $structure): DietAdapter
    {
        $foods = [];
        $measures = [];
        $repo = Container::foods();
        foreach (DietAdapter::foodIds($structure) as $id) {
            $f = $repo->find($id);
            if ($f !== null) {
                $foods[$id] = ['name' => (string) $f['name'], 'kcal' => $f['kcal'] === null ? null : (float) $f['kcal']];
                $measures[$id] = array_map(static fn(array $m): array => ['id' => (int) $m['id'], 'name' => (string) $m['name'], 'grams' => (float) $m['grams']], $repo->measures($id));
            }
        }
        $menu = require APP_ROOT . '/data/dietas/cardapio.php';
        return new DietAdapter($menu['tags'], $foods, $measures);
    }

    private function authPatient(Request $r, string $action): int
    {
        $user = $r->attributes['user'];
        $id = (int) $r->params['id'];
        Staff::assert($user, $r, $id);
        Container::gate()->authorize($user, $action, Policy::NUTRICAO, $id, $r);
        if (Container::patients()->find($id) === null) {
            throw new HttpException(404);
        }
        return $id;
    }

    private static function int(mixed $v, int $min, int $max): ?int
    {
        if (!is_string($v) || !ctype_digit($v)) {
            return null;
        }
        $n = (int) $v;
        return $n < $min || $n > $max ? null : $n;
    }

    private function page(string $template, string $title, Request $r, array $data, string $active): Response
    {
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $r->attributes['user']['name'], 'active' => $active,
        ], 'Layout/views/layout.php'));
    }
}
