<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Install\SeedLoader;
use App\Nutrition\FoodRepository;
use Tests\DbTestCase;

final class FoodRepositoryTest extends DbTestCase
{
    public function test_normalize(): void
    {
        $this->assertSame('pao, trigo, frances', FoodRepository::normalize('Pão, Trigo,  FRANCÊS'));
    }

    public function test_search_is_accent_and_word_insensitive(): void
    {
        $names = array_column(Container::foods()->search('pao frances'), 'name');
        $this->assertContains('Pão, trigo, francês', $names);
        $this->assertContains('Torrada, pão francês', $names);
        $this->assertSame([], Container::foods()->search('p'));             // menos de 2 letras: nada
        $this->assertSame([], Container::foods()->search('p '));
    }

    public function test_search_includes_measures_key_and_own_first(): void
    {
        $id = $this->db->insert('foods', ['source' => 'proprio', 'name' => 'Pão da casa', 'name_search' => 'pao da casa', 'kcal' => 250]);
        $mid = $this->db->insert('food_measures', ['food_id' => $id, 'source' => 'proprio', 'name' => 'fatia', 'grams' => 30]);
        $this->db->insert('food_measures', ['food_id' => $id, 'source' => 'proprio', 'name' => 'inativa', 'grams' => 10, 'active' => 0]);
        $res = Container::foods()->search('pao');
        $this->assertSame($id, (int) $res[0]['id']);
        $this->assertSame([$mid], array_map('intval', array_column($res[0]['measures'], 'id')));
        $this->assertSame([], $res[1]['measures']);
        $this->assertSame('fatia', Container::foods()->findMeasure($mid)['name']);
    }

    public function test_inactive_hidden_from_search_but_found_by_id(): void
    {
        $this->db->run('UPDATE foods SET active = 0 WHERE id = 53');
        $this->assertNotContains(53, array_map('intval', array_column(Container::foods()->search('pao trigo frances'), 'id')));
        $this->assertSame('Pão, trigo, francês', Container::foods()->find(53)['name']);
    }

    public function test_own_food_ids_start_at_100000(): void
    {
        $id = $this->db->insert('foods', ['source' => 'proprio', 'name' => 'Bolo da vó', 'name_search' => 'bolo da vo', 'kcal' => 350]);
        $this->assertGreaterThanOrEqual(100000, $id);
    }

    public function test_seed_reload_keeps_own_foods_and_deactivation(): void
    {
        $own = $this->db->insert('foods', ['source' => 'proprio', 'name' => 'Bolo da vó', 'name_search' => 'bolo da vo']);
        $this->db->run('UPDATE foods SET active = 0 WHERE id = 53');
        SeedLoader::loadFile($this->db, APP_ROOT . '/database/seeds/food_1_taco.sql');
        $this->assertSame(0, (int) Container::foods()->find(53)['active']);
        $this->assertNotNull(Container::foods()->find($own));
    }
}
