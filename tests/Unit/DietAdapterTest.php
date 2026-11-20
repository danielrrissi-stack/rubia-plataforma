<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Diets\DietAdapter;
use PHPUnit\Framework\TestCase;

final class DietAdapterTest extends TestCase
{
    private function adapter(): DietAdapter
    {
        $menu = require APP_ROOT . '/data/dietas/cardapio.php';
        $foods = [
            457 => ['name' => 'Leite, de vaca, desnatado, UHT', 'kcal' => 34.2],
            582 => ['name' => 'Soja, extrato solúvel, natural, fluido', 'kcal' => 39.0],
            53 => ['name' => 'Pão, trigo, francês', 'kcal' => 300.0],
            533 => ['name' => 'Cuscuz, de milho, cozido com sal', 'kcal' => 113.0],
            488 => ['name' => 'Ovo, de galinha, inteiro, cozido/10minutos', 'kcal' => 146.0],
            461 => ['name' => 'Queijo, minas, frescal', 'kcal' => 264.0],
            410 => ['name' => 'Frango, peito, sem pele, grelhado', 'kcal' => 159.0],
            301 => ['name' => 'Merluza, filé, assado', 'kcal' => 122.0],
            239 => ['name' => 'Morango, cru', 'kcal' => 30.0],
            182 => ['name' => 'Banana, prata, crua', 'kcal' => 98.0],
            478 => ['name' => 'Coco, água de', 'kcal' => 22.0],
        ];
        $measures = [
            53 => [['id' => 5301, 'name' => 'unidade', 'grams' => 50.0]],
            488 => [['id' => 48801, 'name' => 'unidade', 'grams' => 45.0]],
            457 => [['id' => 45704, 'name' => 'xícara de chá', 'grams' => 200.0]],
        ];
        return new DietAdapter($menu['tags'], $foods, $measures);
    }

    private static function day(array ...$items): array
    {
        return ['v' => 1, 'meals' => [['name' => 'Café da manhã', 'time' => '07:00', 'note' => '', 'options' => [['items' => $items]]]]];
    }

    private static function it(int $food, float $qty, ?int $mid = null, array $subs = []): array
    {
        return ['food_id' => $food, 'qty' => $qty, 'measure_id' => $mid, 'note' => '', 'subs' => $subs];
    }

    private static function items(array $res): array
    {
        return $res['structure']['meals'][0]['options'][0]['items'];
    }

    public function test_no_restriction_keeps_everything(): void
    {
        $s = self::day(self::it(53, 1, 5301), self::it(457, 1, 45704));
        $res = $this->adapter()->adapt($s, []);
        $this->assertSame($s, $res['structure']);
        $this->assertSame([], $res['changes']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_lactose_keeps_food_with_note(): void
    {
        $res = $this->adapter()->adapt(self::day(self::it(457, 1, 45704)), ['lactose']);
        $it = self::items($res)[0];
        $this->assertSame(457, $it['food_id']);
        $this->assertSame('versão sem lactose', $it['note']);
        $this->assertNotEmpty($res['changes']);
    }

    public function test_milk_protein_allergy_swaps_to_soy_with_same_kcal(): void
    {
        $it = self::items($this->adapter()->adapt(self::day(self::it(457, 1, 45704)), ['leite']))[0];
        $this->assertSame(582, $it['food_id']);
        $this->assertEquals(175.0, $it['qty']); // 200 g x 34,2 / 39 = 175 g
        $this->assertNull($it['measure_id']);
    }

    public function test_milk_and_soy_allergy_removes_with_warning(): void
    {
        $res = $this->adapter()->adapt(self::day(self::it(457, 1, 45704), self::it(182, 1)), ['leite', 'soja']);
        $this->assertCount(1, self::items($res));
        $this->assertStringContainsString('retirado', implode(' ', $res['warnings']));
    }

    public function test_gluten_bread_to_cuscuz_and_egg_to_cheese(): void
    {
        $items = self::items($this->adapter()->adapt(self::day(self::it(53, 1, 5301), self::it(488, 1, 48801)), ['gluten', 'ovo']));
        $this->assertSame(533, $items[0]['food_id']);
        $this->assertEquals(135.0, $items[0]['qty']); // 50 g x 300 / 113 = 132,7 -> 135 g
        $this->assertSame(461, $items[1]['food_id']);
    }

    public function test_fish_to_chicken_and_restricted_subs_removed(): void
    {
        $s = self::day(self::it(410, 120, null, [self::it(301, 150)]), self::it(301, 140, null, [self::it(410, 110)]));
        $items = self::items($this->adapter()->adapt($s, ['peixe']));
        $this->assertSame([], $items[0]['subs']);
        $this->assertSame(410, $items[1]['food_id']);
        $this->assertEquals(105.0, $items[1]['qty']); // 140 x 122 / 159 = 107,4 -> 105
    }

    public function test_lactose_only_does_not_drop_dairy_subs(): void
    {
        $s = self::day(self::it(488, 1, 48801, [self::it(461, 30)]));
        $this->assertCount(1, self::items($this->adapter()->adapt($s, ['lactose']))[0]['subs']);
    }

    public function test_other_text_only_warns(): void
    {
        $res = $this->adapter()->adapt(self::day(self::it(239, 200), self::it(182, 1)), [], 'morangos, kiwi');
        $this->assertCount(2, self::items($res));
        $this->assertCount(1, $res['warnings']);
        $this->assertStringContainsString('Morango, cru', $res['warnings'][0]);
    }
}
