<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class PortalFoodTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    /** @return array{0:array,1:array,2:array} responsavel, crianca, nutricionista */
    private function family(): array
    {
        $nutri = $this->makeUser('nutricionista');
        $g = $this->makeUser('responsavel');
        $p = $this->makePatient(['full_name' => 'Ana Souza', 'birth_date' => '2016-05-10', 'sex' => 'F', 'created_by' => $nutri['id']]);
        $this->db->insert('guardian_links', ['patient_id' => $p['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $nutri['id']]);
        return [$g, $p, $nutri];
    }

    private function item(string $name, array $subs = []): array
    {
        return [
            'food_id' => 53, 'food_name' => $name, 'qty' => 1, 'measure_id' => 1, 'measure_name' => 'unidade',
            'measure_plural' => 'unidades', 'grams' => 50.0, 'note' => '', 'subs' => $subs,
            'per100' => ['kcal' => 300.0, 'protein' => 8.0, 'carb' => 58.0, 'fat' => 3.0, 'fiber' => 2.0],
        ];
    }

    public function testFamilySeesCurrentPlanInSimpleWords(): void
    {
        [$g, $p, $nutri] = $this->family();
        $sub = $this->item('Tapioca');
        $structure = ['v' => 1, 'meals' => [['name' => 'Café da manhã', 'time' => '07:00', 'note' => '', 'options' => [['items' => [$this->item('Pão, trigo, francês', [$sub])]]]]]];
        $repo = Container::mealPlans();
        $id = $repo->createDraft((int) $p['id'], 'estruturado', ['title' => 'Plano vigente', 'structure' => $structure], (int) $nutri['id']);
        $repo->publish($id, (int) $nutri['id']);
        $repo->createDraft((int) $p['id'], 'texto', ['title' => 'Rascunho secreto', 'body_text' => 'x'], (int) $nutri['id']);
        $this->loginAs($g);
        $res = $this->get('/portal/p/' . $p['id'] . '/alimentacao');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('1 unidade de Pão, trigo, francês', $res->body);
        $this->assertStringContainsString('ou ', $res->body);
        $this->assertStringNotContainsString('kcal', $res->body);
        $this->assertStringNotContainsString('Proteína', $res->body);
        $this->assertStringNotContainsString('Rascunho secreto', $res->body);
        $print = $this->get('/portal/p/' . $p['id'] . '/alimentacao/imprimir');
        $this->assertSame(200, $print->status);
        $this->assertStringContainsString('Dra. Rubia', $print->body);
        $this->assertStringContainsString('Ana', $print->body);
        $this->assertStringContainsString('Publicado em ' . date('d/m/Y') . ' por ' . $nutri['name'], $print->body);
        $this->assertStringContainsString('data-print', $print->body);
        $this->assertStringNotContainsString('kcal', $print->body);
        $home = $this->get('/portal/p/' . $p['id']);
        $this->assertStringContainsString('Alimentação', $home->body);
    }

    public function testDraftAndEndedNotShown(): void
    {
        [$g, $p, $nutri] = $this->family();
        $repo = Container::mealPlans();
        $repo->createDraft((int) $p['id'], 'texto', ['title' => 'Rascunho', 'body_text' => 'x'], (int) $nutri['id']);
        $this->loginAs($g);
        $this->assertStringContainsString('Nenhum plano alimentar no momento.', $this->get('/portal/p/' . $p['id'] . '/alimentacao')->body);
        $draft = $repo->draft((int) $p['id']);
        $repo->publish((int) $draft['id'], (int) $nutri['id']);
        $repo->end((int) $draft['id'], 'fim', (int) $nutri['id']);
        $this->assertStringContainsString('Nenhum plano alimentar no momento.', $this->get('/portal/p/' . $p['id'] . '/alimentacao')->body);
        $this->assertStringContainsString('Nenhum plano alimentar no momento.', $this->get('/portal/p/' . $p['id'] . '/alimentacao/imprimir')->body);
    }

    public function testOtherFamilyForbidden(): void
    {
        [$g] = $this->family();
        $other = $this->makePatient(['birth_date' => '2016-01-01']);
        $this->loginAs($g);
        $this->assertSame(403, $this->get('/portal/p/' . $other['id'] . '/alimentacao')->status);
        $this->assertSame(403, $this->get('/portal/p/' . $other['id'] . '/alimentacao/imprimir')->status);
    }
}
