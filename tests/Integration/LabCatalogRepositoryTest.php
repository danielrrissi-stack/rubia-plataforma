<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Install\SeedLoader;
use App\Labs\InvalidLabException;
use App\Labs\LabClassifier;
use Tests\DbTestCase;

final class LabCatalogRepositoryTest extends DbTestCase
{
    private function range(array $over = []): array
    {
        return array_merge([
            'sex' => null, 'age_min_months' => 24, 'age_max_months' => 239,
            'borderline_high' => 100.0, 'abnormal_high' => 130.0, 'borderline_low' => null, 'abnormal_low' => null,
            'source' => 'Dra.',
        ], $over);
    }

    public function test_seed_has_catalog_and_child_lipid_ranges(): void
    {
        $repo = Container::labCatalog();
        $this->assertSame(['glicemia', 'hba1c', 'insulina', 'colesterol_total', 'hdl', 'ldl', 'nao_hdl', 'triglicerides', 'tgo', 'tgp'], array_column($repo->tests(), 'code'));
        $tg = $repo->ranges($repo->findByCode('triglicerides')['id']);
        $this->assertSame(90.0, LabClassifier::pickRange($tg, 'F', 120)['borderline_high']);
        $this->assertSame([], $repo->ranges($repo->findByCode('insulina')['id']));
        $this->assertSame(0, (int) $repo->findByCode('ldl')['reference_confirmed']);
    }

    public function test_seed_ranges_shape_and_key_values(): void
    {
        $repo = Container::labCatalog();
        $tg = $repo->ranges($repo->findByCode('triglicerides')['id']);
        $young = LabClassifier::pickRange($tg, 'M', 119);
        $this->assertSame(75.0, $young['borderline_high']);
        $this->assertSame(100.0, $young['abnormal_high']);
        $this->assertSame(24, $young['age_min_months']);
        $this->assertSame(119, $young['age_max_months']);
        $this->assertNull($young['sex']);
        $this->assertNull($young['borderline_low']);
        $adult = LabClassifier::pickRange($tg, 'F', 300);
        $this->assertNull($adult['age_max_months']);
        $this->assertSame(150.0, $adult['abnormal_high']);
        $this->assertNull(LabClassifier::pickRange($tg, 'F', 12));

        $hdl = $repo->ranges($repo->findByCode('hdl')['id']);
        $child = LabClassifier::pickRange($hdl, 'F', 100);
        $this->assertSame(45.0, $child['borderline_low']);
        $this->assertSame(39.0, $child['abnormal_low']);
        $this->assertSame('limitrofe_baixo', LabClassifier::classify(42.0, $child));

        $tgp = $repo->ranges($repo->findByCode('tgp')['id']);
        $this->assertSame(27.0, LabClassifier::pickRange($tgp, 'M', 150)['abnormal_high']);
        $this->assertSame(23.0, LabClassifier::pickRange($tgp, 'F', 150)['abnormal_high']);
        $this->assertNull(LabClassifier::pickRange($tgp, 'F', 300));

        $ldl = $repo->ranges($repo->findByCode('ldl')['id']);
        $this->assertNull(LabClassifier::pickRange($ldl, 'M', 300));
        $this->assertSame([], $repo->ranges($repo->findByCode('tgo')['id']));
        $this->assertSame(6.5, LabClassifier::pickRange($repo->ranges($repo->findByCode('hba1c')['id']), 'F', 500)['abnormal_high']);

        $all = $repo->rangesByTest();
        $this->assertCount(2, $all[$repo->findByCode('tgp')['id']]);
        $this->assertArrayNotHasKey($repo->findByCode('insulina')['id'], $all);
    }

    public function test_seed_rerun_keeps_doctor_edits(): void
    {
        $repo = Container::labCatalog();
        $u = $this->makeUser('medico');
        $ldl = $repo->findByCode('ldl');
        $repo->saveRanges((int) $ldl['id'], [$this->range()], (int) $u['id'], new Request('POST', '/'));
        SeedLoader::loadDir($this->db, APP_ROOT . '/database/seeds');
        $ranges = $repo->ranges((int) $ldl['id']);
        $this->assertCount(1, $ranges);
        $this->assertSame(100.0, $ranges[0]['borderline_high']);
        $this->assertSame('Dra.', $ranges[0]['source']);
        $this->assertSame(1, (int) $repo->find((int) $ldl['id'])['reference_confirmed']);
        $audit = $this->db->one("SELECT * FROM audit_log WHERE entity = 'lab_ranges' AND entity_id = ? AND action = 'update'", [$ldl['id']]);
        $this->assertNotNull($audit);
        $this->assertStringContainsString('110', (string) $audit['before_json']);
    }

    public function test_overlapping_ranges_rejected(): void
    {
        $repo = Container::labCatalog();
        $u = $this->makeUser('medico');
        $id = (int) $repo->findByCode('colesterol_total')['id'];
        $before = $repo->ranges($id);
        try {
            $repo->saveRanges($id, [
                $this->range(),
                $this->range(['sex' => 'F', 'age_min_months' => 100, 'age_max_months' => 150]),
            ], (int) $u['id'], new Request('POST', '/'));
            $this->fail('Deveria rejeitar faixas sobrepostas.');
        } catch (InvalidLabException $e) {
            $this->assertNotEmpty($e->errors);
        }
        $this->assertSame($before, $repo->ranges($id));
        $this->assertSame(0, (int) $repo->find($id)['reference_confirmed']);
    }

    public function test_same_sex_overlap_rejected_but_adjacent_ok(): void
    {
        $repo = Container::labCatalog();
        $u = $this->makeUser('medico');
        $id = (int) $repo->findByCode('tgp')['id'];
        $repo->saveRanges($id, [
            $this->range(['sex' => 'M', 'age_min_months' => 24, 'age_max_months' => 119]),
            $this->range(['sex' => 'M', 'age_min_months' => 120, 'age_max_months' => null]),
            $this->range(['sex' => 'F', 'age_min_months' => 24, 'age_max_months' => null]),
        ], (int) $u['id'], new Request('POST', '/'));
        $this->assertCount(3, $repo->ranges($id));
        $this->expectException(InvalidLabException::class);
        $repo->saveRanges($id, [
            $this->range(['sex' => 'M', 'age_min_months' => 24, 'age_max_months' => null]),
            $this->range(['sex' => 'M', 'age_min_months' => 300, 'age_max_months' => 400]),
        ], (int) $u['id'], new Request('POST', '/'));
    }

    public function test_borderline_must_precede_abnormal(): void
    {
        $repo = Container::labCatalog();
        $u = $this->makeUser('medico');
        $id = (int) $repo->findByCode('ldl')['id'];
        $before = $repo->ranges($id);
        try {
            $repo->saveRanges($id, [$this->range(['borderline_high' => 130.0, 'abnormal_high' => 110.0])], (int) $u['id'], new Request('POST', '/'));
            $this->fail('Deveria rejeitar limítrofe depois do alterado.');
        } catch (InvalidLabException $e) {
            $this->assertStringContainsString('LDL-colesterol', implode(' ', $e->errors));
        }
        $this->assertSame($before, $repo->ranges($id));
    }

    public function test_age_min_after_max_rejected(): void
    {
        $repo = Container::labCatalog();
        $u = $this->makeUser('medico');
        $id = (int) $repo->findByCode('ldl')['id'];
        $this->expectException(InvalidLabException::class);
        $repo->saveRanges($id, [$this->range(['age_min_months' => 200, 'age_max_months' => 100])], (int) $u['id'], new Request('POST', '/'));
    }

    public function test_save_test_creates_validates_and_confirm(): void
    {
        $repo = Container::labCatalog();
        $u = $this->makeUser('medico');
        $r = new Request('POST', '/');
        $id = $repo->saveTest(null, ['name' => 'Vitamina D', 'unit' => 'ng/mL', 'group_name' => 'outros', 'sort_order' => 200, 'decimals' => 1, 'active' => 1], (int) $u['id'], $r);
        $this->assertGreaterThanOrEqual(1000, $id);
        $t = $repo->find($id);
        $this->assertSame('Vitamina D', $t['name']);
        $this->assertNull($t['code']);
        $this->assertSame((int) $u['id'], (int) $t['created_by']);
        $this->assertSame('Vitamina D', $repo->tests()[count($repo->tests()) - 1]['name']);

        try {
            $repo->saveTest(null, ['name' => 'vitamina d', 'unit' => 'ng/mL'], (int) $u['id'], $r);
            $this->fail('Nome repetido deveria ser rejeitado.');
        } catch (InvalidLabException $e) {
            $this->assertNotEmpty($e->errors);
        }
        try {
            $repo->saveTest(null, ['name' => '', 'unit' => ''], (int) $u['id'], $r);
            $this->fail('Nome e unidade são obrigatórios.');
        } catch (InvalidLabException $e) {
            $this->assertCount(2, $e->errors);
        }

        $repo->saveTest($id, ['name' => 'Vitamina D (25-OH)', 'unit' => 'ng/mL', 'group_name' => 'outros', 'sort_order' => 200, 'decimals' => 1, 'active' => 0], (int) $u['id'], $r);
        $this->assertSame('Vitamina D (25-OH)', $repo->find($id)['name']);
        $this->assertNotContains($id, array_map('intval', array_column($repo->tests(), 'id')));
        $this->assertContains($id, array_map('intval', array_column($repo->tests(false), 'id')));
        // Inativo nao bloqueia o nome
        $repo->saveTest(null, ['name' => 'Vitamina D (25-OH)', 'unit' => 'ng/mL'], (int) $u['id'], $r);

        $glic = (int) $repo->findByCode('glicemia')['id'];
        $repo->confirm($glic, (int) $u['id'], $r);
        $this->assertSame(1, (int) $repo->find($glic)['reference_confirmed']);
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE entity = 'lab_tests' AND entity_id = ? AND action = 'confirm'", [$glic]));
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE entity = 'lab_tests' AND entity_id = ? AND action = 'create'", [$id]));
    }
}
