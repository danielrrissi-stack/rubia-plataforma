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
}
