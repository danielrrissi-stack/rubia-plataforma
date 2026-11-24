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

    private function item(string $name, array $subs = [], int $foodId = 53): array
    {
        return [
            'food_id' => $foodId, 'food_name' => $name, 'qty' => 1, 'measure_id' => 1, 'measure_name' => 'unidade',
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

    public function testOptionHeadingsAndTacoMarker(): void
    {
        [$g, $p, $nutri] = $this->family();
        $noFiber = $this->item('Biscoito');
        $noFiber['per100']['fiber'] = null;
        $structure = ['v' => 1, 'meals' => [
            ['name' => 'Café da manhã', 'time' => '', 'note' => '', 'options' => [['items' => [$this->item('Pão'), $noFiber]]]],
            ['name' => 'Lanche', 'time' => '', 'note' => '', 'options' => [['items' => [$this->item('Banana')]], ['items' => [$this->item('Maçã')]]]],
        ]];
        $repo = Container::mealPlans();
        $id = $repo->createDraft((int) $p['id'], 'estruturado', ['title' => 'Plano', 'structure' => $structure], (int) $nutri['id']);
        $repo->publish($id, (int) $nutri['id']);

        $this->loginAs($g);
        $fam = $this->get('/portal/p/' . $p['id'] . '/alimentacao')->body;
        $this->assertSame(1, substr_count($fam, 'Opção 1</h4>')); // so o Lanche (2 opcoes) tem "Opcao 1"
        $this->assertStringContainsString('Opção 2</h4>', $fam);
        $this->assertStringNotContainsString('TACO', $fam);

        $this->db->insert('professional_access', ['patient_id' => $p['id'], 'professional_id' => $nutri['id'], 'granted_by' => $nutri['id']]);
        $this->loginAs($nutri);
        $staff = $this->get('/planos/' . $id)->body;
        $this->assertSame(1, substr_count($staff, 'valor não disponível na TACO'));
        $this->assertSame(1, substr_count($staff, 'Opção 1 <span'));
        $this->assertStringContainsString('Opção 2 <span', $staff);
    }

    /** Publica um plano v2 de $n dias comecando em $start; cada dia tem um alimento com o nome "Dia{i}". */
    private function publishMulti(array $p, array $nutri, string $start, int $n): void
    {
        $days = [];
        for ($i = 0; $i < $n; $i++) {
            $days[] = ['meals' => [['name' => 'Café', 'time' => '', 'note' => '', 'options' => [['items' => [$this->item('Dia' . $i, [], 1000 + $i)]]]]]];
        }
        $repo = Container::mealPlans();
        $id = $repo->createDraft((int) $p['id'], 'estruturado', ['title' => 'Plano dias', 'structure' => ['v' => 2, 'start' => $start, 'days' => $days]], (int) $nutri['id']);
        $repo->publish($id, (int) $nutri['id']);
    }

    private static function ago(int $days): string
    {
        return date('Y-m-d', strtotime(($days >= 0 ? '-' : '+') . abs($days) . ' days'));
    }

    public function testPortalShowsOnlySevenDaysFromToday(): void
    {
        [$g, $p, $nutri] = $this->family();
        $this->publishMulti($p, $nutri, self::ago(3), 14);
        $this->loginAs($g);
        $body = $this->get('/portal/p/' . $p['id'] . '/alimentacao')->body;
        $this->assertSame(7, substr_count($body, '<section class="plano-dia"'));
        $this->assertStringContainsString('id="dia-' . date('Y-m-d') . '"', $body);
        $this->assertStringContainsString('Dia3', $body);
        $this->assertStringNotContainsString('Dia2<', $body);
        $this->assertStringContainsString('Dia9', $body);
        $this->assertStringNotContainsString('Dia10', $body);
        $this->assertStringContainsString('aria-label="Dias do cardápio"', $body);
        $this->assertStringContainsString('href="#dia-' . date('Y-m-d') . '">Hoje<', $body);
        $this->assertStringContainsString('>Amanhã<', $body);
    }

    public function testPortalFuturePlanStartsAtStart(): void
    {
        [$g, $p, $nutri] = $this->family();
        $this->publishMulti($p, $nutri, self::ago(-5), 14);
        $this->loginAs($g);
        $body = $this->get('/portal/p/' . $p['id'] . '/alimentacao')->body;
        $this->assertSame(7, substr_count($body, '<section class="plano-dia"'));
        $this->assertStringContainsString('id="dia-' . self::ago(-5) . '"', $body);
        $this->assertStringNotContainsString('id="dia-' . date('Y-m-d') . '"', $body);
        $this->assertStringNotContainsString('>Hoje<', $body);
    }

    public function testPortalAfterEndCyclesLastSeven(): void
    {
        [$g, $p, $nutri] = $this->family();
        $this->publishMulti($p, $nutri, self::ago(23), 14); // ultimo dia: ha 10 dias
        $this->loginAs($g);
        $body = $this->get('/portal/p/' . $p['id'] . '/alimentacao')->body;
        $this->assertSame(7, substr_count($body, '<section class="plano-dia"'));
        for ($i = 0; $i < 7; $i++) {
            $this->assertStringContainsString('id="dia-' . self::ago(-$i) . '"', $body);
        }
        $this->assertStringNotContainsString('de Dia6</li>', $body);
        $this->assertStringContainsString('de Dia7</li>', $body);
        $this->assertStringContainsString('de Dia13</li>', $body);
    }

    public function testPortalHidesDateRangeTitleOfV2Plan(): void
    {
        [$g, $p, $nutri] = $this->family();
        $this->publishMulti($p, $nutri, self::ago(23), 14); // terminou ha 10 dias
        $title = 'Plano alimentar – ' . date('d/m', strtotime('-23 days')) . ' a ' . date('d/m', strtotime('-10 days'));
        $this->db->run('UPDATE meal_plans SET title = ? WHERE patient_id = ?', [$title, $p['id']]);
        $this->loginAs($g);
        foreach (['/alimentacao', '/alimentacao/imprimir'] as $suffix) {
            $body = $this->get('/portal/p/' . $p['id'] . $suffix)->body;
            $this->assertStringNotContainsString($title, $body);
            $this->assertStringContainsString('Plano alimentar', $body);
            $this->assertStringContainsString('ublicado em ' . date('d/m/Y'), $body);
        }
    }

    public function testPortalHasShoppingListAndNoKcal(): void
    {
        [$g, $p, $nutri] = $this->family();
        $this->publishMulti($p, $nutri, date('Y-m-d'), 7);
        $this->loginAs($g);
        foreach (['/alimentacao', '/alimentacao/imprimir'] as $suffix) {
            $body = $this->get('/portal/p/' . $p['id'] . $suffix)->body;
            $this->assertStringContainsString('<h2>Lista de compras</h2>', $body);
            $this->assertStringContainsString('class="lista-compras"', $body);
            $this->assertStringContainsString('Dia0 — 1 unidade', $body);
            $this->assertStringContainsString('Dia6 — 1 unidade', $body);
            $this->assertStringNotContainsString('kcal', $body);
        }
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
