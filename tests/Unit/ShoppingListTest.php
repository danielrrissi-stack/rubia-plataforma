<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Nutrition\ShoppingList;
use PHPUnit\Framework\TestCase;

final class ShoppingListTest extends TestCase
{
    private static function item(int $id, string $name, float $qty, float $grams, ?string $measure = null, array $subs = []): array
    {
        return [
            'food_id' => $id, 'food_name' => $name, 'qty' => $qty, 'measure_id' => $measure === null ? null : $id * 100 + 1,
            'measure_name' => $measure, 'grams' => $grams, 'note' => '', 'subs' => $subs,
        ];
    }

    /** Um dia v1 com uma refeicao e uma opcao (mais opcoes extras, se houver). */
    private static function day(array $items, array $extraOptions = []): array
    {
        return ['v' => 1, 'meals' => [['name' => 'R', 'options' => array_merge([['items' => $items]], $extraOptions)]]];
    }

    public function test_sums_units_across_days(): void
    {
        $d = self::day([self::item(1, 'Banana', 1, 80, 'unidade')]);
        $this->assertSame([['name' => 'Banana', 'text' => '3 unidades']], ShoppingList::build([$d, $d, $d]));
        $this->assertSame([['name' => 'Banana', 'text' => '1 unidade']], ShoppingList::build([$d]));
        $f = self::day([self::item(2, 'Pão de forma', 2, 50, 'fatia')]);
        $this->assertSame('1 fatia', ShoppingList::build([self::day([self::item(2, 'Pão de forma', 1, 25, 'fatia')])])[0]['text']);
        $this->assertSame('4 fatias', ShoppingList::build([$f, $f])[0]['text']);
    }

    public function test_grams_round_up_to_50_and_kg(): void
    {
        $a = self::day([self::item(3, 'Frango', 120, 120)]);
        $this->assertSame('400 g', ShoppingList::build([$a, $a, $a])[0]['text']);
        $b = self::day([self::item(3, 'Frango', 140, 140)]);
        $this->assertSame('1,3 kg', ShoppingList::build(array_fill(0, 9, $b))[0]['text']);
    }

    public function test_milk_in_litres(): void
    {
        $d = self::day([self::item(4, 'Leite, de vaca, integral', 200, 200)]);
        $this->assertSame('1 L', ShoppingList::build(array_fill(0, 5, $d))[0]['text']);
        $this->assertSame('1,5 L', ShoppingList::build(array_fill(0, 7, $d))[0]['text']);
        $s = self::day([self::item(5, 'Suco de uva', 200, 200)]);
        $this->assertSame('0,5 L', ShoppingList::build([$s], [5 => 'Bebidas (alcoólicas e não alcoólicas)'])[0]['text']);
    }

    public function test_mixed_units_and_grams_become_grams(): void
    {
        $u = self::day([self::item(1, 'Banana', 1, 80, 'unidade')]);
        $g = self::day([self::item(1, 'Banana', 100, 100)]);
        $this->assertSame('200 g', ShoppingList::build([$u, $g])[0]['text']);
    }

    public function test_subs_and_other_options_ignored(): void
    {
        $sub = self::item(9, 'Tapioca', 30, 30);
        $other = ['items' => [self::item(8, 'Maçã', 1, 130, 'unidade')]];
        $d = self::day([self::item(1, 'Banana', 1, 80, 'unidade', [$sub])], [$other]);
        $this->assertSame([['name' => 'Banana', 'text' => '1 unidade']], ShoppingList::build([$d]));
    }

    public function test_sorted_by_name_and_skips_zero(): void
    {
        $d = self::day([self::item(7, 'Zebu', 100, 100), self::item(6, 'Arroz', 100, 100), self::item(5, 'Nada', 0, 0)]);
        $this->assertSame(['Arroz', 'Zebu'], array_column(ShoppingList::build([$d]), 'name'));
    }

    public function test_sorting_ignores_accents(): void
    {
        $d = self::day([self::item(1, 'Óleo de soja', 10, 10), self::item(2, 'Banana', 10, 10), self::item(3, 'Água de coco', 10, 10)]);
        $this->assertSame(['Água de coco', 'Banana', 'Óleo de soja'], array_column(ShoppingList::build([$d]), 'name'));
    }

    public function test_near_one_kilo_shows_kg_not_1000_g(): void
    {
        $d = self::day([self::item(3, 'Frango', 960, 960)]);
        $this->assertSame('1,0 kg', ShoppingList::build([$d])[0]['text']);
        $e = self::day([self::item(3, 'Frango', 950, 950)]);
        $this->assertSame('950 g', ShoppingList::build([$e])[0]['text']);
    }
}
