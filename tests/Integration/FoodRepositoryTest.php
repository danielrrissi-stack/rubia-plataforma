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

    public function test_measures_keep_insertion_order(): void
    {
        // Ordem da POF (a primeira e a mais comum) e o padrao do editor; nao ordenar por nome.
        $id = $this->db->insert('foods', ['source' => 'proprio', 'name' => 'Bolo da casa', 'name_search' => 'bolo da casa', 'kcal' => 300]);
        $a = $this->db->insert('food_measures', ['food_id' => $id, 'source' => 'proprio', 'name' => 'pedaço médio', 'grams' => 60]);
        $b = $this->db->insert('food_measures', ['food_id' => $id, 'source' => 'proprio', 'name' => 'fatia fina', 'grams' => 30]);
        $this->assertSame([$a, $b], array_map('intval', array_column(Container::foods()->measures($id), 'id')));
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

    public function test_create_own_food_normalizes_and_audits(): void
    {
        $u = $this->makeUser('medico');
        $id = Container::foods()->createOwn(['name' => 'Bolo de Banana da Casa', 'group_name' => 'Receita', 'kcal' => 250.5, 'protein_g' => 4, 'carb_g' => null], (int) $u['id']);
        $this->assertGreaterThanOrEqual(100000, $id);
        $f = Container::foods()->find($id);
        $this->assertSame('proprio', $f['source']);
        $this->assertSame('bolo de banana da casa', $f['name_search']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE entity='foods' AND action='create' AND entity_id=?", [$id])['c']);
        $this->expectException(\RuntimeException::class);
        Container::foods()->createOwn(['name' => 'X', 'kcal' => 10], (int) $u['id']);
    }

    public function test_own_food_validation_ranges(): void
    {
        $u = $this->makeUser('medico');
        foreach ([['name' => 'Ok ok', 'kcal' => null], ['name' => 'Ok ok', 'kcal' => 901], ['name' => 'Ok ok', 'kcal' => 10, 'fat_g' => 101], ['name' => 'Ok ok', 'kcal' => -1]] as $bad) {
            try {
                Container::foods()->createOwn($bad, (int) $u['id']);
                $this->fail('deveria recusar');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_update_own_and_taco_is_readonly(): void
    {
        $u = $this->makeUser('medico');
        $id = Container::foods()->createOwn(['name' => 'Sopa da casa', 'kcal' => 50], (int) $u['id']);
        Container::foods()->updateOwn($id, ['name' => 'Sopa de legumes', 'kcal' => 60], (int) $u['id']);
        $this->assertSame('sopa de legumes', Container::foods()->find($id)['name_search']);
        Container::foods()->setActive($id, false, (int) $u['id']);
        $this->assertSame(0, (int) Container::foods()->find($id)['active']);
        try {
            Container::foods()->updateOwn(53, ['name' => 'Outro', 'kcal' => 1], (int) $u['id']);
            $this->fail('TACO nao editavel');
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        Container::foods()->setActive(53, false, (int) $u['id']);
    }

    public function test_add_measure_to_taco_food_and_pof_readonly(): void
    {
        $u = $this->makeUser('medico');
        $mid = Container::foods()->addMeasure(53, ['name' => 'pão da padaria', 'name_plural' => 'pães da padaria', 'grams' => 48.5], (int) $u['id']);
        $this->assertSame('proprio', Container::foods()->findMeasure($mid)['source']);
        $ids = [];
        foreach (Container::foods()->search('pao frances') as $f) {
            if ((int) $f['id'] === 53) {
                $ids = array_map('intval', array_column($f['measures'], 'id'));
            }
        }
        $this->assertContains($mid, $ids);
        Container::foods()->updateMeasure($mid, ['name' => 'pão grande', 'grams' => 60], (int) $u['id']);
        $this->assertSame('pão grande', Container::foods()->findMeasure($mid)['name']);
        Container::foods()->setMeasureActive($mid, false, (int) $u['id']);
        $this->assertSame(0, (int) Container::foods()->findMeasure($mid)['active']);
        $pof = $this->db->one("SELECT id FROM food_measures WHERE source = 'pof' LIMIT 1");
        $this->assertNotNull($pof);
        $this->expectException(\RuntimeException::class);
        Container::foods()->updateMeasure((int) $pof['id'], ['name' => 'x x', 'grams' => 10], (int) $u['id']);
    }
}
