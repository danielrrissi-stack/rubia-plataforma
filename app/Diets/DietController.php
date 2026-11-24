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
use App\Nutrition\PlanDays;
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
        $builder = DietBuilder::fromDatabase(Container::db());
        $monday = (new \DateTimeImmutable('2026-01-01'))->setISODate(2026, (int) $diet['week'], 1);
        $names = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
        $resolver = new MealPlanResolver(Container::foods());
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $monday->modify('+' . $i . ' day');
            $built = $builder->buildDay((string) $diet['band'], $date->format('Y-m-d'), (int) $diet['kcal_target']);
            try {
                $structure = $resolver->resolve(['v' => 1, 'meals' => $built['meals']], true);
            } catch (InvalidPlanException $e) {
                throw new \RuntimeException('Dieta pronta inválida: ' . implode(' ', $e->errors));
            }
            $day = MealPlanCalculator::day($structure);
            $days[] = ['label' => $names[$i] . ', ' . $date->format('d/m'), 'structure' => $structure, 'day' => $day,
                'percents' => MealPlanCalculator::macroPercents($day)];
        }
        return $this->page('Diets/views/show.php', (string) $diet['code'], $r, ['diet' => $diet, 'days' => $days], '/modelos-plano');
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
        if ($kcal === null || !in_array($kcal, DietBuilder::levels($band), true)) {
            Session::flash('erro', 'Escolha as calorias da sugestão.');
            return $back;
        }
        $n = (string) ($r->post['dias'] ?? '');
        $start = (string) ($r->post['inicio'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $okStart = $d !== false && $d->format('Y-m-d') === $start
            && $start >= date('Y-m-d', strtotime('-7 days')) && $start <= date('Y-m-d', strtotime('+7 days'));
        if (!in_array($n, ['7', '14'], true) || !$okStart) {
            Session::flash('erro', 'Escolha a duração (7 ou 14 dias) e a data de início da sugestão.');
            return $back;
        }
        try {
            $sug = $this->buildSuggestion($pid, $band, $kcal, $start, (int) $n);
        } catch (InvalidPlanException $e) {
            Session::flash('erro', 'Não foi possível gerar a sugestão: ' . implode(' ', $e->errors));
            return $back;
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', 'Não foi possível montar a sugestão: ' . $e->getMessage());
            return $back;
        }
        $adapted = ['changes' => $sug['changes'], 'warnings' => $sug['warnings']];

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
                'title' => $sug['title'], 'body_text' => null,
                'guidance_text' => $sug['guidance'], 'structure' => $sug['structure'],
            ], (int) $user['id'], $r, null, null, $sug['code'], (int) $n);
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
            return $back;
        }
        $msg = 'Sugestão de ' . $n . ' dias criada como rascunho (código ' . $sug['code'] . '). Avalie, ajuste e publique.';
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
     * @return array{band:?string,kcal:?int,start:string,days:int,levels:array,restrictions:array,allergyText:?string,goalKcal:?int}
     */
    public static function patientCard(array $patient, array $user): array
    {
        $pid = (int) $patient['id'];
        $band = DietBuilder::bandForAge(Age::years((string) $patient['birth_date'], date('Y-m-d')));
        $goal = Container::energyGoals()->current($pid);
        $goalKcal = $goal === null ? null : (int) $goal['get_kcal'];
        $kcal = $band === null ? null : self::suggestedKcal($band, $goalKcal);
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
            'band' => $band, 'kcal' => $kcal, 'start' => date('Y-m-d'), 'days' => 14, 'goalKcal' => $goalKcal,
            'levels' => ['infantil' => DietBuilder::levels('infantil'), 'adulto' => DietBuilder::levels('adulto')],
            'restrictions' => Container::foodRestrictions()->get($pid), 'allergyText' => $allergy,
        ];
    }

    /** Calorias da sugestao: a meta arredondada a 100 e limitada a versao; sem meta, o minimo da versao + 500. */
    public static function suggestedKcal(string $band, ?int $goalKcal): int
    {
        [$lo, $hi] = DietBuilder::BANDS[$band];
        return $goalKcal === null ? $lo + 500 : (int) max($lo, min($hi, round($goalKcal / 100) * 100));
    }

    /** POST /planos/{planId}/continuacao: monta agora a continuacao do plano por dias publicado. */
    public function continueNow(Request $r): Response
    {
        $user = $r->attributes['user'];
        $plan = Container::mealPlans()->find((int) $r->params['planId']) ?? throw new HttpException(404);
        $pid = (int) $plan['patient_id'];
        Staff::assert($user, $r, $pid);
        Container::gate()->authorize($user, 'edit', Policy::NUTRICAO, $pid, $r);
        $back = Response::redirect('/pacientes/' . $pid . '/plano');
        if ($plan['status'] !== 'publicado' || !is_array($plan['structure']) || !PlanDays::isMulti($plan['structure'])) {
            Session::flash('erro', 'Só é possível continuar um plano por dias publicado.');
            return $back;
        }
        $contId = (int) ($plan['continuation_draft_id'] ?? 0);
        if ($contId > 0) {
            $cont = Container::mealPlans()->find($contId);
            if ($cont !== null && $cont['status'] === 'rascunho' && $cont['discarded_at'] === null) {
                Session::flash('ok', 'A continuação deste plano já está em rascunho.');
                return Response::redirect('/planos/' . $contId . '/editar');
            }
        }
        try {
            $draftId = Container::planContinuation()->generate((int) $plan['id'], (int) $user['id'], false, date('Y-m-d'));
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (InvalidPlanException $e) {
            Session::flash('erro', 'Não foi possível gerar a continuação: ' . implode(' ', $e->errors));
            return $back;
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
            return $back;
        }
        Session::flash('ok', 'Continuação criada como rascunho. Revise e publique.');
        return Response::redirect('/planos/' . $draftId . '/editar');
    }

    /**
     * Monta, adapta (restricoes do paciente) e resolve N dias a partir de $startYmd.
     *
     * @return array{structure:array,title:string,guidance:string,code:string,changes:list<string>,warnings:list<string>}
     * @throws InvalidPlanException
     */
    public function buildSuggestion(int $pid, string $band, int $kcal, string $startYmd, int $n): array
    {
        $built = DietBuilder::fromDatabase(Container::db())->buildDays($band, $kcal, $startYmd, $n);
        $restr = Container::foodRestrictions()->get($pid);
        $all = ['meals' => []];
        foreach ($built['structure']['days'] as $day) {
            array_push($all['meals'], ...$day['meals']);
        }
        $adapter = $this->adapter($all);
        $changes = [];
        $warnings = [];
        $days = [];
        foreach ($built['structure']['days'] as $day) {
            $res = $adapter->adapt(['v' => 1, 'meals' => $day['meals']], $restr['items'], $restr['other_text']);
            $days[] = ['meals' => $res['structure']['meals']];
            $changes = array_merge($changes, $res['changes']);
            $warnings = array_merge($warnings, $res['warnings']);
        }
        $structure = (new MealPlanResolver(Container::foods()))->resolve(['v' => 2, 'start' => $built['structure']['start'], 'days' => $days], true);
        return ['structure' => $structure, 'title' => $built['title'], 'guidance' => $built['guidance'], 'code' => $built['code'],
            'changes' => array_values(array_unique($changes)), 'warnings' => array_values(array_unique($warnings))];
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
