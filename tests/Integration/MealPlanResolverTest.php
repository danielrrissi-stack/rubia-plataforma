<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Nutrition\InvalidPlanException;
use App\Nutrition\MealPlanResolver;
use Tests\DbTestCase;

final class MealPlanResolverTest extends DbTestCase
{
    private function resolver(): MealPlanResolver
    {
        return new MealPlanResolver(Container::foods());
    }

    private function plan(array $item, string $time = '07:00'): array
    {
        return ['meals' => [['name' => 'Café da manhã', 'time' => $time, 'note' => '', 'options' => [['items' => [$item]]]]]];
    }

    /** @return list<string> */
    private function errors(array $input, bool $allowEmpty = false): array
    {
        try {
            $this->resolver()->resolve($input, $allowEmpty);
        } catch (InvalidPlanException $e) {
            return $e->errors;
        }
        $this->fail('Esperava InvalidPlanException');
    }

    public function test_resolves_item_with_pof_measure(): void
    {
        $m = Container::foods()->measures(53)[0];
        $out = $this->resolver()->resolve($this->plan(['food_id' => 53, 'qty' => '1', 'measure_id' => $m['id'], 'note' => 'tostado']));
        $it = $out['meals'][0]['options'][0]['items'][0];
        $this->assertSame(1, $out['v']);
        $this->assertSame('Pão, trigo, francês', $it['food_name']);
        $this->assertSame(1.0, $it['qty']);
        $this->assertSame((int) $m['id'], $it['measure_id']);
        $this->assertEqualsWithDelta((float) $m['grams'], $it['grams'], 1e-9);
        $this->assertSame($m['name'], $it['measure_name']);
        $this->assertSame(300.0, $it['per100']['kcal']);
        $this->assertSame('tostado', $it['note']);
        $this->assertSame([], $it['subs']);
    }

    public function test_comma_qty_in_grams(): void
    {
        $out = $this->resolver()->resolve($this->plan(['food_id' => 53, 'qty' => '1,5', 'measure_id' => null]));
        $it = $out['meals'][0]['options'][0]['items'][0];
        $this->assertSame(1.5, $it['grams']);
        $this->assertNull($it['measure_id']);
    }

    public function test_substitutes_are_resolved(): void
    {
        $out = $this->resolver()->resolve($this->plan(['food_id' => 53, 'qty' => '50', 'measure_id' => null, 'subs' => [['food_id' => 561, 'qty' => '100', 'measure_id' => null]]]));
        $sub = $out['meals'][0]['options'][0]['items'][0]['subs'][0];
        $this->assertSame('Feijão, carioca, cozido', $sub['food_name']);
        $this->assertArrayNotHasKey('subs', $sub);
        $this->assertArrayNotHasKey('note', $sub);
    }

    public function test_zero_qty(): void
    {
        $e = $this->errors($this->plan(['food_id' => 53, 'qty' => '0', 'measure_id' => null]));
        $this->assertStringContainsString('Café da manhã, opção 1, item 1', $e[0]);
        $this->assertStringContainsString('maior que zero', $e[0]);
    }

    public function test_non_numeric_or_huge_qty_is_an_error_not_a_type_error(): void
    {
        $e = $this->errors($this->plan(['food_id' => 53, 'qty' => 'abc', 'measure_id' => null]));
        $this->assertStringContainsString('maior que zero', $e[0]);
        $e = $this->errors($this->plan(['food_id' => 53, 'qty' => '5001', 'measure_id' => null]));
        $this->assertStringContainsString('maior que zero', $e[0]);
    }

    public function test_measure_of_other_food(): void
    {
        $other = Container::foods()->measures(561)[0];
        $e = $this->errors($this->plan(['food_id' => 53, 'qty' => '1', 'measure_id' => $other['id']]));
        $this->assertStringContainsString('medida não pertence ao alimento', $e[0]);
        $e = $this->errors($this->plan(['food_id' => 53, 'qty' => '1', 'measure_id' => 99999999]));
        $this->assertStringContainsString('medida não encontrada', $e[0]);
    }

    public function test_unknown_food(): void
    {
        $e = $this->errors($this->plan(['food_id' => 999999, 'qty' => '1', 'measure_id' => null]));
        $this->assertStringContainsString('alimento não encontrado', $e[0]);
    }

    public function test_meal_count_and_time(): void
    {
        $meal = ['name' => 'Lanche', 'time' => '', 'note' => '', 'options' => [['items' => [['food_id' => 53, 'qty' => '1', 'measure_id' => null]]]]];
        $e = $this->errors(['meals' => array_fill(0, 13, $meal)]);
        $this->assertStringContainsString('no máximo 12 refeições', implode(' ', $e));
        $e = $this->errors(['meals' => []]);
        $this->assertStringContainsString('ao menos uma refeição', implode(' ', $e));
        $e = $this->errors($this->plan(['food_id' => 53, 'qty' => '1', 'measure_id' => null], '7h'));
        $this->assertStringContainsString('horário no formato HH:MM', implode(' ', $e));
        $out = $this->resolver()->resolve(['meals' => [$meal]]);
        $this->assertSame('', $out['meals'][0]['time']);
    }

    public function test_meal_name_rules(): void
    {
        $in = $this->plan(['food_id' => 53, 'qty' => '1', 'measure_id' => null]);
        $in['meals'][0]['name'] = ' ';
        $this->assertStringContainsString('nome', implode(' ', $this->errors($in)));
        $in['meals'][0]['name'] = str_repeat('a', 61);
        $this->assertStringContainsString('nome', implode(' ', $this->errors($in)));
    }

    public function test_empty_option_rules(): void
    {
        $in = ['meals' => [['name' => 'Almoço', 'time' => '', 'note' => '', 'options' => [['items' => []]]]]];
        $this->assertStringContainsString('Almoço, opção 1', implode(' ', $this->errors($in)));
        $this->resolver()->resolve($in, true);                                   // rascunho
        $in['meals'][0]['note'] = 'Livre';
        $out = $this->resolver()->resolve($in);                                    // refeição com nota
        $this->assertSame([], $out['meals'][0]['options'][0]['items']);
    }

    public function test_limits(): void
    {
        $item = ['food_id' => 53, 'qty' => '1', 'measure_id' => null];
        $in = $this->plan($item);
        $in['meals'][0]['options'] = array_fill(0, 6, ['items' => [$item]]);
        $this->assertStringContainsString('no máximo 5 opções', implode(' ', $this->errors($in)));
        $in = $this->plan($item);
        $in['meals'][0]['options'][0]['items'] = array_fill(0, 31, $item);
        $this->assertStringContainsString('no máximo 30 itens', implode(' ', $this->errors($in)));
        $in = $this->plan($item + ['subs' => array_fill(0, 11, $item)]);
        $this->assertStringContainsString('no máximo 10 substitutos', implode(' ', $this->errors($in)));
    }

    public function test_inactive_food_still_resolves(): void
    {
        $this->db->run('UPDATE foods SET active = 0 WHERE id = 53');
        $out = $this->resolver()->resolve($this->plan(['food_id' => 53, 'qty' => '1', 'measure_id' => null]));
        $this->assertSame('Pão, trigo, francês', $out['meals'][0]['options'][0]['items'][0]['food_name']);
    }

    public function test_collects_errors(): void
    {
        $this->assertGreaterThanOrEqual(1, count($this->errors($this->plan(['food_id' => 999999, 'qty' => '0', 'measure_id' => null]))));
    }

    public function test_non_integer_measure_is_an_error(): void
    {
        $e = $this->errors($this->plan(['food_id' => 53, 'qty' => '1', 'measure_id' => 'abc']));
        $this->assertStringContainsString('Café da manhã, opção 1, item 1: medida inválida.', implode(' ', $e));
        $e = $this->errors($this->plan(['food_id' => 53, 'qty' => '1', 'measure_id' => ['1']]));
        $this->assertStringContainsString('medida inválida', implode(' ', $e));
    }

    public function test_array_fields_are_validation_errors_not_warnings(): void
    {
        $in = $this->plan(['food_id' => 53, 'qty' => ['1'], 'measure_id' => null, 'note' => ['x']]);
        $in['meals'][0]['name'] = ['a'];
        $in['meals'][0]['time'] = ['07:00'];
        $in['meals'][0]['note'] = ['n'];
        $e = implode(' ', $this->errors($in));
        $this->assertStringContainsString('nome da refeição', $e);
        $this->assertStringContainsString('horário', $e);
        $this->assertStringContainsString('observação da refeição inválida', $e);
        $this->assertStringContainsString('maior que zero', $e);
        $this->assertStringContainsString('observação do item inválida', $e);
    }

    private function v2(string $start, int $n, array $badDay = []): array
    {
        $ok = ['food_id' => 53, 'qty' => '1', 'measure_id' => null, 'note' => ''];
        $days = [];
        for ($d = 0; $d < $n; $d++) {
            $days[] = $this->plan(in_array($d, $badDay, true) ? ['qty' => '1', 'note' => ''] : $ok);
        }
        return ['v' => 2, 'start' => $start, 'days' => $days];
    }

    public function test_resolve_v2_keeps_days_and_start(): void
    {
        $out = $this->resolver()->resolve($this->v2('2026-11-23', 2));
        $this->assertSame(2, $out['v']);
        $this->assertSame('2026-11-23', $out['start']);
        $this->assertCount(2, $out['days']);
        $this->assertSame('Pão, trigo, francês', $out['days'][1]['meals'][0]['options'][0]['items'][0]['food_name']);
    }

    public function test_resolve_v2_rejects_bad_start_and_too_many_days(): void
    {
        $this->assertStringContainsString('início', implode(' ', $this->errors($this->v2('2026-13-01', 2))));
        $this->assertStringContainsString('início', implode(' ', $this->errors($this->v2('2026-2-1', 2))));
        $this->assertStringContainsString('31', implode(' ', $this->errors($this->v2('2026-11-23', 32))));
        $this->assertNotSame([], $this->errors($this->v2('2026-11-23', 0)));
    }

    public function test_resolve_v2_error_names_the_date(): void
    {
        $errs = $this->errors($this->v2('2026-11-23', 2, [1]));
        $this->assertStringStartsWith('Dia 24/11 (ter): ', $errs[0]);
    }
}
