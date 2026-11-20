<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class FoodAdminTest extends DbTestCase
{
    private function get(string $path, array $query = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $query));
    }

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(string $role): array
    {
        $u = $this->makeUser($role);
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
        return $u;
    }

    public function testDoctorCreatesOwnFoodAndFindsItInSearch(): void
    {
        $this->loginAs('medico');
        $res = $this->post('/alimentos/novo', ['name' => 'Bolo de banana da casa', 'group_name' => 'Receita', 'kcal' => '250,5', 'protein_g' => '4,2', 'carb_g' => '', 'fat_g' => '', 'fiber_g' => '']);
        $this->assertSame(302, $res->status);
        $json = json_decode($this->get('/alimentos/busca', ['q' => 'bolo banana casa'])->body, true);
        $this->assertSame('proprio', $json['items'][0]['source']);
        $this->assertSame(250.5, $json['items'][0]['per100']['kcal']);
    }

    public function testInvalidNumberShowsMessage(): void
    {
        $this->loginAs('medico');
        $res = $this->post('/alimentos/novo', ['name' => 'Bolo X', 'kcal' => 'abc']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Calorias', $res->body);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM foods WHERE source='proprio'")['c']);
    }

    public function testEditOwnAndTacoReadOnlyButMeasureAllowed(): void
    {
        $u = $this->loginAs('medico');
        $id = Container::foods()->createOwn(['name' => 'Sopa da casa', 'kcal' => 50], (int) $u['id']);
        $this->assertSame(302, $this->post('/alimentos/' . $id . '/editar', ['name' => 'Sopa nova', 'kcal' => '55'])->status);
        $this->assertSame('Sopa nova', Container::foods()->find($id)['name']);
        $this->assertSame(200, $this->get('/alimentos/53/editar')->status);
        $this->assertSame(302, $this->post('/alimentos/53/editar', ['name' => 'Hack', 'kcal' => '1'])->status);
        $this->assertSame('Pão, trigo, francês', Container::foods()->find(53)['name']);
        $this->assertSame(302, $this->post('/alimentos/53/medidas', ['name' => 'pãozinho', 'grams' => '50'])->status);
        $m = $this->db->one("SELECT * FROM food_measures WHERE food_id = 53 AND source='proprio'");
        $this->assertSame('pãozinho', $m['name']);
        $this->assertSame(302, $this->post('/alimentos/medidas/' . $m['id'] . '/editar', ['name' => 'pãozão', 'grams' => '70'])->status);
        $this->assertSame('pãozão', Container::foods()->findMeasure((int) $m['id'])['name']);
        $this->assertSame(302, $this->post('/alimentos/' . $id . '/ativo', ['ativo' => '0'])->status);
        $this->assertSame(0, (int) Container::foods()->find($id)['active']);
    }

    public function testIndexListsAndFilters(): void
    {
        $u = $this->loginAs('nutricionista');
        Container::foods()->createOwn(['name' => 'Tapioca da casa', 'kcal' => 200], (int) $u['id']);
        $res = $this->get('/alimentos', ['q' => 'tapioca', 'meus' => '1']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Tapioca da casa', $res->body);
    }

    public function testSecretaryForbidden(): void
    {
        $this->loginAs('secretaria');
        $this->assertSame(403, $this->get('/alimentos')->status);
        $this->assertSame(403, $this->post('/alimentos/novo', ['name' => 'Bolo X', 'kcal' => '10'])->status);
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM foods WHERE source='proprio'")['c']);
    }

    public function testGuardianForbidden(): void
    {
        $this->loginAs('responsavel');
        $this->assertSame(403, $this->get('/alimentos')->status);
        $this->assertSame(403, $this->post('/alimentos/novo', ['name' => 'Bolo X', 'kcal' => '10'])->status);
    }
}
