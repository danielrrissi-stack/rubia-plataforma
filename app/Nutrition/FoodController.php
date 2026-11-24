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
use App\Support\Num;

/** Base de alimentos para a equipe (nesta fase: so a busca usada pelo editor estruturado). */
final class FoodController
{
    /** GET /alimentos/busca?q= */
    public function search(Request $r): Response
    {
        Staff::assert($r->attributes['user'], $r);
        $q = $r->query['q'] ?? '';
        $q = is_string($q) ? mb_substr(mb_scrub($q, 'UTF-8'), 0, 100, 'UTF-8') : '';
        $items = [];
        foreach (Container::foods()->search($q, 20) as $f) {
            $items[] = [
                'id' => (int) $f['id'],
                'name' => (string) $f['name'],
                'group' => $f['group_name'] === null ? null : (string) $f['group_name'],
                'source' => (string) $f['source'],
                'per100' => [
                    'kcal' => self::num($f['kcal']),
                    'protein' => self::num($f['protein_g']),
                    'carb' => self::num($f['carb_g']),
                    'fat' => self::num($f['fat_g']),
                    'fiber' => self::num($f['fiber_g']),
                ],
                'measures' => array_map(static fn(array $m): array => [
                    'id' => (int) $m['id'],
                    'name' => (string) $m['name'],
                    'plural' => $m['name_plural'] === null ? null : (string) $m['name_plural'],
                    'grams' => (float) $m['grams'],
                ], $f['measures']),
            ];
        }
        return Response::json(['items' => $items]);
    }

    /** GET /alimentos */
    public function index(Request $r): Response
    {
        Staff::assert($r->attributes['user'], $r);
        $q = $r->query['q'] ?? '';
        $q = is_string($q) ? mb_substr(mb_scrub($q, 'UTF-8'), 0, 100, 'UTF-8') : '';
        $onlyOwn = ($r->query['meus'] ?? '') === '1';
        return $this->page('Nutrition/views/foods_index.php', 'Alimentos', $r, [
            'foods' => Container::foods()->adminList($q, $onlyOwn),
            'q' => $q,
            'onlyOwn' => $onlyOwn,
        ]);
    }

    /** GET /alimentos/novo */
    public function createForm(Request $r): Response
    {
        $this->authWrite($r);
        return $this->renderForm($r, null, ['name' => '', 'group_name' => '', 'kcal' => '', 'protein_g' => '', 'carb_g' => '', 'fat_g' => '', 'fiber_g' => ''], []);
    }

    /** POST /alimentos/novo */
    public function create(Request $r): Response
    {
        $user = $this->authWrite($r);
        [$values, $parsed, $errors] = self::readFood($r->post);
        if ($errors === []) {
            try {
                $id = Container::foods()->createOwn($parsed, (int) $user['id'], $r);
                Session::flash('ok', 'Alimento cadastrado. Agora você pode adicionar medidas caseiras.');
                return Response::redirect('/alimentos/' . $id . '/editar');
            } catch (\PDOException $e) {
                throw $e; // erro de banco nunca vai para a tela
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return $this->renderForm($r, null, $values, $errors);
    }

    /** GET /alimentos/{foodId}/editar */
    public function editForm(Request $r): Response
    {
        $this->authWrite($r);
        $food = $this->food($r);
        return $this->renderForm($r, $food, self::valuesFrom($food), []);
    }

    /** POST /alimentos/{foodId}/editar */
    public function update(Request $r): Response
    {
        $user = $this->authWrite($r);
        $food = $this->food($r);
        if ($food['source'] !== 'proprio') {
            Session::flash('erro', 'Alimentos da tabela TACO não podem ser alterados; você pode adicionar medidas caseiras.');
            return Response::redirect('/alimentos/' . $food['id'] . '/editar');
        }
        [$values, $parsed, $errors] = self::readFood($r->post);
        if ($errors === []) {
            try {
                Container::foods()->updateOwn((int) $food['id'], $parsed, (int) $user['id'], $r);
                Session::flash('ok', 'Alimento salvo.');
                return Response::redirect('/alimentos/' . $food['id'] . '/editar');
            } catch (\PDOException $e) {
                throw $e; // erro de banco nunca vai para a tela
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return $this->renderForm($r, $food, $values, $errors);
    }

    /** POST /alimentos/{foodId}/ativo */
    public function toggle(Request $r): Response
    {
        $user = $this->authWrite($r);
        $food = $this->food($r);
        $on = ($r->post['ativo'] ?? '') === '1';
        try {
            Container::foods()->setActive((int) $food['id'], $on, (int) $user['id'], $r);
            Session::flash('ok', $on ? 'Alimento reativado.' : 'Alimento desativado; ele não aparece mais nas buscas.');
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
        }
        return Response::redirect('/alimentos/' . $food['id'] . '/editar');
    }

    /** POST /alimentos/{foodId}/medidas */
    public function addMeasure(Request $r): Response
    {
        $user = $this->authWrite($r);
        $food = $this->food($r);
        [$parsed, $errors] = self::readMeasure($r->post);
        if ($errors === []) {
            try {
                Container::foods()->addMeasure((int) $food['id'], $parsed, (int) $user['id'], $r);
                Session::flash('ok', 'Medida adicionada.');
                return Response::redirect('/alimentos/' . $food['id'] . '/editar');
            } catch (\PDOException $e) {
                throw $e; // erro de banco nunca vai para a tela
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return $this->renderForm($r, $food, self::valuesFrom($food), [], $errors, $r->post);
    }

    /** POST /alimentos/medidas/{measureId}/editar (edita ou ativa/desativa) */
    public function updateMeasure(Request $r): Response
    {
        $user = $this->authWrite($r);
        $m = Container::foods()->findMeasure((int) $r->params['measureId']);
        if ($m === null) {
            throw new HttpException(404, 'Medida não encontrada');
        }
        $back = '/alimentos/' . $m['food_id'] . '/editar';
        try {
            if (isset($r->post['ativo'])) {
                $on = $r->post['ativo'] === '1';
                Container::foods()->setMeasureActive((int) $m['id'], $on, (int) $user['id'], $r);
                Session::flash('ok', $on ? 'Medida reativada.' : 'Medida desativada.');
                return Response::redirect($back);
            }
            [$parsed, $errors] = self::readMeasure($r->post);
            if ($errors !== []) {
                Session::flash('erro', implode(' ', $errors));
                return Response::redirect($back);
            }
            Container::foods()->updateMeasure((int) $m['id'], $parsed, (int) $user['id'], $r);
            Session::flash('ok', 'Medida salva.');
        } catch (\PDOException $e) {
            throw $e; // erro de banco nunca vai para a tela
        } catch (\RuntimeException $e) {
            Session::flash('erro', $e->getMessage());
        }
        return Response::redirect($back);
    }

    private function authWrite(Request $r): array
    {
        $user = $r->attributes['user'];
        Staff::assert($user, $r);
        Container::gate()->authorize($user, 'edit', Policy::NUTRICAO, null, $r);
        return $user;
    }

    private function food(Request $r): array
    {
        $food = Container::foods()->find((int) $r->params['foodId']);
        if ($food === null) {
            throw new HttpException(404, 'Alimento não encontrado');
        }
        return $food;
    }

    private function renderForm(Request $r, ?array $food, array $values, array $errors, array $measureErrors = [], array $measurePosted = []): Response
    {
        $str = static fn(string $k): string => is_string($measurePosted[$k] ?? null) ? $measurePosted[$k] : '';
        return $this->page('Nutrition/views/food_form.php', $food === null ? 'Novo alimento' : (string) $food['name'], $r, [
            'food' => $food,
            'values' => $values,
            'errors' => $errors,
            'measureErrors' => $measureErrors,
            'measurePosted' => ['name' => $str('name'), 'name_plural' => $str('name_plural'), 'grams' => $str('grams')],
            'measures' => $food === null ? [] : Container::foods()->allMeasures((int) $food['id']),
        ]);
    }

    private function page(string $template, string $title, Request $r, array $data): Response
    {
        return Response::html(View::render($template, $data + [
            'title' => $title, 'userName' => $r->attributes['user']['name'], 'active' => '/alimentos',
        ], 'Layout/views/layout.php'));
    }

    private static function valuesFrom(array $food): array
    {
        $fmt = static fn(mixed $v): string => $v === null ? '' : str_replace('.', ',', (string) (float) $v);
        return [
            'name' => (string) $food['name'], 'group_name' => (string) ($food['group_name'] ?? ''),
            'kcal' => $fmt($food['kcal']), 'protein_g' => $fmt($food['protein_g']), 'carb_g' => $fmt($food['carb_g']),
            'fat_g' => $fmt($food['fat_g']), 'fiber_g' => $fmt($food['fiber_g']),
        ];
    }

    private static function str(array $post, string $k): string
    {
        return is_string($post[$k] ?? null) ? trim(mb_scrub($post[$k], 'UTF-8')) : '';
    }

    /** @return array{0: array, 1: array, 2: string[]} valores para reexibir, valores convertidos, erros */
    private static function readFood(array $post): array
    {
        $values = [];
        foreach (['name', 'group_name', 'kcal', 'protein_g', 'carb_g', 'fat_g', 'fiber_g'] as $k) {
            $values[$k] = self::str($post, $k);
        }
        $parsed = ['name' => $values['name'], 'group_name' => $values['group_name']];
        $errors = [];
        $labels = ['kcal' => 'Calorias', 'protein_g' => 'Proteína', 'carb_g' => 'Carboidrato', 'fat_g' => 'Gordura', 'fiber_g' => 'Fibra'];
        foreach ($labels as $k => $label) {
            try {
                $parsed[$k] = Num::parse($values[$k]);
            } catch (\InvalidArgumentException) {
                $errors[] = $label . ': informe um número válido (por exemplo, 250,5).';
                $parsed[$k] = null;
            }
        }
        return [$values, $parsed, $errors];
    }

    /** @return array{0: array, 1: string[]} */
    private static function readMeasure(array $post): array
    {
        $errors = [];
        try {
            $g = Num::parse(self::str($post, 'grams'));
        } catch (\InvalidArgumentException) {
            $g = null;
            $errors[] = 'Peso da medida: informe um número válido em gramas (por exemplo, 30,5).';
        }
        if ($errors === [] && $g === null) {
            $errors[] = 'Informe o peso da medida em gramas.';
        }
        return [['name' => self::str($post, 'name'), 'name_plural' => self::str($post, 'name_plural'), 'grams' => $g], $errors];
    }

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
