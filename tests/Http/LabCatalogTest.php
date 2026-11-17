<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use Tests\DbTestCase;

final class LabCatalogTest extends DbTestCase
{
    private function get(string $path): Response
    {
        return App::create()->handle(new Request('GET', $path));
    }

    private function post(string $path, array $data): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function ldl(): int
    {
        return (int) Container::labCatalog()->findByCode('ldl')['id'];
    }

    private function base(array $faixas): array
    {
        return ['name' => 'LDL', 'unit' => 'mg/dL', 'group_name' => 'lipidios', 'sort_order' => '10', 'decimals' => '0', 'active' => '1', 'faixa' => $faixas];
    }

    private function row(array $o = []): array
    {
        return $o + ['sex' => '', 'min_a' => '2', 'min_m' => '0', 'max_a' => '19', 'max_m' => '11', 'bh' => '100', 'ah' => '130', 'bl' => '', 'al' => '', 'source' => 'teste'];
    }

    public function test_doctor_edits_ranges_and_confirms(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $this->db->run('UPDATE lab_tests SET reference_confirmed = 0 WHERE id = ?', [$id]);
        $res = $this->post('/catalogo-exames/' . $id . '/editar', $this->base([$this->row(), $this->row(['min_a' => '', 'min_m' => '', 'max_a' => '', 'max_m' => '', 'bh' => '', 'ah' => '', 'source' => ''])]));
        $this->assertSame(302, $res->status);
        $ranges = Container::labCatalog()->ranges($id);
        $this->assertCount(1, $ranges);
        $this->assertSame(24, $ranges[0]['age_min_months']);
        $this->assertSame(239, $ranges[0]['age_max_months']);
        $this->assertSame(100.0, $ranges[0]['borderline_high']);
        $this->assertSame(130.0, $ranges[0]['abnormal_high']);
        $this->assertSame(1, (int) Container::labCatalog()->find($id)['reference_confirmed']);
        $a = $this->db->one("SELECT * FROM audit_log WHERE entity = 'lab_ranges' AND action = 'update' AND entity_id = ?", [$id]);
        $this->assertNotNull($a);
        $this->assertNotNull($a['before_json']);
        $this->assertNotNull($a['after_json']);
    }

    public function test_confirm_route_confirms_only_pending(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $this->db->run('UPDATE lab_tests SET reference_confirmed = 0 WHERE id = ?', [$id]);
        $this->assertStringContainsString('a confirmar', $this->get('/catalogo-exames')->body);
        $this->assertSame(302, $this->post('/catalogo-exames/' . $id . '/confirmar', [])->status);
        $this->assertSame(1, (int) Container::labCatalog()->find($id)['reference_confirmed']);
    }

    public function test_overlap_shows_error_and_keeps_old_ranges(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $before = Container::labCatalog()->ranges($id);
        $res = $this->post('/catalogo-exames/' . $id . '/editar', $this->base([
            $this->row(), $this->row(['min_a' => '10', 'max_a' => '15']),
        ]));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('role="alert"', $res->body);
        $this->assertSame($before, Container::labCatalog()->ranges($id));
    }

    public function test_pt_br_decimal_commas_are_accepted(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $res = $this->post('/catalogo-exames/' . $id . '/editar', $this->base([$this->row(['bh' => '5,7', 'ah' => '6,5'])]));
        $this->assertSame(302, $res->status);
        $this->assertSame(5.7, Container::labCatalog()->ranges($id)[0]['borderline_high']);
    }

    public function test_new_test_appears_in_collection_form(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->makePatient(['birth_date' => '2014-05-10', 'sex' => 'M', 'responsible_professional_id' => $med['id'], 'created_by' => $med['id']]);
        $this->loginAs($med);
        $res = $this->post('/catalogo-exames/novo', ['name' => 'Vitamina D', 'unit' => 'ng/mL', 'group_name' => 'outros', 'sort_order' => '200', 'decimals' => '1', 'active' => '1', 'faixa' => [$this->row(['min_a' => '', 'min_m' => '', 'max_a' => '', 'max_m' => '', 'bh' => '', 'ah' => '', 'source' => ''])]]);
        $this->assertSame(302, $res->status);
        $form = $this->get('/pacientes/' . $p['id'] . '/exames/nova')->body;
        $this->assertStringContainsString('Vitamina D', $form);
        $this->assertMatchesRegularExpression('#Outros.*Vitamina D#s', $form);
    }

    public function test_unchecked_active_deactivates(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $data = $this->base([$this->row()]);
        unset($data['active']);
        $this->post('/catalogo-exames/' . $id . '/editar', $data);
        $this->assertSame(0, (int) Container::labCatalog()->find($id)['active']);
    }

    public function test_catalog_403_for_nutritionist_and_family(): void
    {
        foreach (['nutricionista', 'secretaria', 'responsavel', 'paciente'] as $role) {
            $this->loginAs($this->makeUser($role));
            $this->assertSame(403, $this->get('/catalogo-exames')->status, $role);
            $this->assertSame(403, $this->post('/catalogo-exames/' . $this->ldl() . '/confirmar', [])->status, $role);
        }
    }

    public function test_saving_without_range_changes_keeps_pending_confirmation(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $ranges = Container::labCatalog()->ranges($id);
        $fmt = static fn(?float $v): string => $v === null ? '' : str_replace('.', ',', (string) $v);
        $rows = array_map(static fn(array $g): array => [
            'sex' => (string) ($g['sex'] ?? ''),
            'min_a' => (string) intdiv($g['age_min_months'], 12), 'min_m' => (string) ($g['age_min_months'] % 12),
            'max_a' => $g['age_max_months'] === null ? '' : (string) intdiv($g['age_max_months'], 12),
            'max_m' => $g['age_max_months'] === null ? '' : (string) ($g['age_max_months'] % 12),
            'bh' => $fmt($g['borderline_high']), 'ah' => $fmt($g['abnormal_high']),
            'bl' => $fmt($g['borderline_low']), 'al' => $fmt($g['abnormal_low']),
            'source' => (string) ($g['source'] ?? ''),
        ], $ranges);
        $this->db->run('UPDATE lab_tests SET reference_confirmed = 0 WHERE id = ?', [$id]);
        $data = $this->base($rows);
        unset($data['active']); // so desativa
        $this->assertSame(302, $this->post('/catalogo-exames/' . $id . '/editar', $data)->status);
        $t = Container::labCatalog()->find($id);
        $this->assertSame(0, (int) $t['active']);
        $this->assertSame(0, (int) $t['reference_confirmed']);
        $this->assertSame($ranges, Container::labCatalog()->ranges($id));
    }

    public function test_changing_a_cut_confirms(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $this->db->run('UPDATE lab_tests SET reference_confirmed = 0 WHERE id = ?', [$id]);
        $this->post('/catalogo-exames/' . $id . '/editar', $this->base([$this->row(['bh' => '101'])]));
        $this->assertSame(1, (int) Container::labCatalog()->find($id)['reference_confirmed']);
    }

    public function test_gap_between_ranges_shows_non_blocking_warning_and_help_text(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $res = $this->post('/catalogo-exames/' . $id . '/editar', $this->base([
            $this->row(['min_a' => '0', 'max_a' => '9', 'max_m' => '0']),
            $this->row(['min_a' => '10', 'min_m' => '0', 'max_a' => '', 'max_m' => '']),
        ]));
        $this->assertSame(302, $res->status); // nao bloqueia
        $this->assertCount(2, Container::labCatalog()->ranges($id));
        $body = $this->get('/catalogo-exames/' . $id . '/editar')->body;
        $this->assertStringContainsString('ficam sem faixa', $body);
        $this->assertStringContainsString('9 anos e 1 mês a 9 anos e 11 meses', $body);
        $this->assertStringContainsString('A idade final conta inteira: para “até os 10 anos” use 9 anos e 11 meses.', $body);
    }

    public function test_contiguous_ranges_show_no_gap_warning(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $this->post('/catalogo-exames/' . $id . '/editar', $this->base([
            $this->row(['min_a' => '0', 'max_a' => '9', 'max_m' => '11']),
            $this->row(['min_a' => '10', 'min_m' => '0', 'max_a' => '', 'max_m' => '']),
        ]));
        $this->assertStringNotContainsString('ficam sem faixa', $this->get('/catalogo-exames/' . $id . '/editar')->body);
    }

    public function test_gap_is_per_sex(): void
    {
        $g = static fn(?string $sex, int $min, ?int $max): array => ['sex' => $sex, 'age_min_months' => $min, 'age_max_months' => $max];
        $this->assertSame([], \App\Labs\LabCatalogController::gapWarnings([$g('M', 0, 119), $g('M', 120, null), $g('F', 0, null)]));
        $w = \App\Labs\LabCatalogController::gapWarnings([$g('M', 0, 100), $g('M', 120, null), $g('F', 0, null)]);
        $this->assertCount(1, $w);
        $this->assertStringContainsString('sexo M', $w[0]);
    }

    public function test_validation_error_rerenders_typed_range_rows(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $id = $this->ldl();
        $res = $this->post('/catalogo-exames/' . $id . '/editar', $this->base([
            $this->row(['sex' => 'F', 'min_a' => '3', 'min_m' => '4', 'max_a' => '8', 'max_m' => '2', 'bh' => '99,5', 'ah' => '120', 'bl' => '40', 'al' => '30', 'source' => 'Fonte X']),
            $this->row(['sex' => 'M', 'min_a' => '1', 'min_m' => '', 'max_a' => 'x', 'max_m' => '', 'bh' => '10', 'ah' => '', 'source' => 'Outra']),
        ]));
        $this->assertSame(200, $res->status);
        $b = $res->body;
        $this->assertStringContainsString('role="alert"', $b);
        $this->assertMatchesRegularExpression('#name="faixa\[0\]\[sex\]".*?<option value="F" selected>#s', $b);
        $this->assertMatchesRegularExpression('#name="faixa\[1\]\[sex\]".*?<option value="M" selected>#s', $b);
        $expected = ['faixa[0][min_a]' => '3', 'faixa[0][min_m]' => '4', 'faixa[0][max_a]' => '8', 'faixa[0][max_m]' => '2',
            'faixa[0][bh]' => '99,5', 'faixa[0][ah]' => '120', 'faixa[0][bl]' => '40', 'faixa[0][al]' => '30',
            'faixa[0][source]' => 'Fonte X', 'faixa[1][max_a]' => 'x', 'faixa[1][source]' => 'Outra'];
        foreach ($expected as $name => $val) {
            $this->assertStringContainsString('name="' . $name . '" value="' . $val . '"', $b, $name);
        }
    }

    public function test_nav_label_is_catalog(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $this->assertStringContainsString('>Catálogo de exames<', $this->get('/catalogo-exames')->body);
    }
}
