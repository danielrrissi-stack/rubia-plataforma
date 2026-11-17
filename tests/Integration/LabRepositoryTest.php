<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Labs\InvalidLabException;
use Tests\DbTestCase;

final class LabRepositoryTest extends DbTestCase
{
    private ?array $medico = null;

    private function setUpPatient(string $birth = '2016-05-10', string $sex = 'F'): array
    {
        $this->medico ??= $this->makeUser('medico');
        return $this->makePatient(['birth_date' => $birth, 'sex' => $sex, 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function tid(string $code): int
    {
        return (int) Container::labCatalog()->findByCode($code)['id'];
    }

    private function add(int $patientId, string $on, array $byCode): int
    {
        $values = [];
        foreach ($byCode as $code => $v) {
            $values[$this->tid($code)] = $v;
        }
        return Container::labs()->create($patientId, ['collected_on' => $on, 'lab_name' => null, 'notes' => null], $values, (int) $this->medico['id'], new Request('POST', '/'));
    }

    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (InvalidLabException $e) {
            return $e->errors;
        }
        $this->fail('Deveria lançar InvalidLabException.');
    }

    public function test_create_validates_and_audits(): void
    {
        $p = $this->setUpPatient();
        $r = new Request('POST', '/');
        $uid = (int) $this->medico['id'];
        $labs = Container::labs();
        $d = ['collected_on' => '2026-05-10'];
        $vals = [$this->tid('glicemia') => '98', $this->tid('hba1c') => '5,7', $this->tid('insulina') => '', $this->tid('ldl') => '12abc'];

        $errs = $this->errors(fn() => $labs->create((int) $p['id'], $d, $vals, $uid, $r));
        $this->assertContains('LDL-colesterol: valor inválido.', $errs);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM lab_collections')['n']);

        $ok = [$this->tid('glicemia') => '98', $this->tid('hba1c') => '5,7', $this->tid('insulina') => ''];
        $this->assertNotEmpty($this->errors(fn() => $labs->create((int) $p['id'], ['collected_on' => '2999-01-01'], $ok, $uid, $r)));
        $this->assertNotEmpty($this->errors(fn() => $labs->create((int) $p['id'], ['collected_on' => '2016-05-09'], $ok, $uid, $r)));
        $this->assertNotEmpty($this->errors(fn() => $labs->create((int) $p['id'], ['collected_on' => '2026-02-30'], $ok, $uid, $r)));
        $this->assertContains('Informe pelo menos um resultado.', $this->errors(fn() => $labs->create((int) $p['id'], $d, [$this->tid('glicemia') => ' '], $uid, $r)));
        $this->assertContains('Exame inválido.', $this->errors(fn() => $labs->create((int) $p['id'], $d, [999999 => '1'], $uid, $r)));
        $big = $this->errors(fn() => $labs->create((int) $p['id'], $d, [$this->tid('glicemia') => '999999999'], $uid, $r));
        $this->assertStringEndsWith(': valor inválido.', $big[0]);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM lab_collections')['n']);
    }

    public function test_create_valid_persists_and_audits(): void
    {
        $p = $this->setUpPatient();
        $id = $this->add((int) $p['id'], '2026-05-10', ['glicemia' => '98', 'hba1c' => '5,7', 'insulina' => '']);
        $c = Container::labs()->find($id);
        $this->assertCount(2, $c['results']);
        $this->assertSame(98.0, $c['results'][0]['value_num']);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM lab_collections')['n']);
        $a = $this->db->one("SELECT * FROM audit_log WHERE entity = 'lab_collections' AND entity_id = ? AND action = 'create'", [$id]);
        $this->assertNotNull($a);
        $this->assertSame(2, count(json_decode((string) $a['after_json'], true)['results']));
    }

    public function test_update_release_delete_audited(): void
    {
        $p = $this->setUpPatient();
        $uid = (int) $this->medico['id'];
        $r = new Request('POST', '/');
        $labs = Container::labs();
        $id = $this->add((int) $p['id'], '2026-05-10', ['glicemia' => '98']);
        $labs->update($id, ['collected_on' => '2026-05-09'], [$this->tid('hba1c') => '6'], $uid, $r);
        $c = $labs->find($id);
        $this->assertSame('2026-05-09', $c['collected_on']);
        $this->assertSame([$this->tid('hba1c')], array_column($c['results'], 'test_id'));
        $labs->release($id, $uid, $r);
        $this->assertNotNull($labs->find($id)['released_at']);
        $labs->unrelease($id, $uid, $r);
        $this->assertNull($labs->find($id)['released_at']);
        $labs->delete($id, $uid, $r);
        $this->assertNotNull($labs->find($id));
        $this->assertSame([], $labs->collections((int) $p['id']));
        $this->errors(fn() => $labs->update($id, ['collected_on' => '2026-05-09'], [$this->tid('hba1c') => '6'], $uid, $r));
        foreach (['create', 'update', 'release', 'unrelease', 'delete'] as $act) {
            $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE entity = 'lab_collections' AND entity_id = ? AND action = ?", [$id, $act]), $act);
        }
    }

    public function test_evolution_classifies_by_age_at_each_collection(): void
    {
        $p = $this->setUpPatient();
        $pid = (int) $p['id'];
        $a = $this->add($pid, '2026-05-09', ['triglicerides' => '95']);
        $b = $this->add($pid, '2026-05-10', ['triglicerides' => '95']);
        $c = $this->add($pid, '2026-05-09', ['triglicerides' => '80']);
        $d = $this->add($pid, '2026-05-10', ['triglicerides' => '80']);
        $cells = Container::labs()->evolution($pid)['rows'][0]['cells'];
        $this->assertSame('limitrofe_alto', $cells[$a]['class']);
        $this->assertSame('limitrofe_alto', $cells[$b]['class']);
        $this->assertSame('limitrofe_alto', $cells[$c]['class']);
        $this->assertSame('normal', $cells[$d]['class']);
        $this->assertSame('95', $cells[$a]['value']);

        $young = $this->setUpPatient('2025-01-01');
        $e = $this->add((int) $young['id'], '2026-06-01', ['ldl' => '150']);
        $cell = Container::labs()->evolution((int) $young['id'])['rows'][0]['cells'][$e];
        $this->assertNull($cell['class']);
        $this->assertTrue($cell['no_range']);
    }

    public function test_evolution_limit_and_total(): void
    {
        $p = $this->setUpPatient();
        for ($i = 1; $i <= 3; $i++) {
            $this->add((int) $p['id'], '2026-0' . $i . '-10', ['glicemia' => '90']);
        }
        $ev = Container::labs()->evolution((int) $p['id'], 2);
        $this->assertCount(2, $ev['collections']);
        $this->assertSame(3, $ev['total']);
        $this->assertSame('2026-03-10', $ev['collections'][0]['collected_on']);
    }

    public function test_homa_only_with_both_values_in_same_collection(): void
    {
        $p = $this->setUpPatient();
        $a = $this->add((int) $p['id'], '2026-05-10', ['glicemia' => '90', 'insulina' => '14']);
        $b = $this->add((int) $p['id'], '2026-06-10', ['glicemia' => '90']);
        $homa = Container::labs()->evolution((int) $p['id'])['homa'];
        $this->assertSame('3,11', $homa['cells'][$a]['value']);
        $this->assertArrayNotHasKey($b, $homa['cells']);
    }

    public function test_deleted_and_unreleased_hidden_where_required(): void
    {
        $p = $this->setUpPatient();
        $pid = (int) $p['id'];
        $uid = (int) $this->medico['id'];
        $r = new Request('POST', '/');
        $labs = Container::labs();
        $a = $this->add($pid, '2026-05-10', ['ldl' => '140']);
        $b = $this->add($pid, '2026-06-10', ['ldl' => '100']);
        $this->assertSame([], $labs->familyView($pid));
        $labs->release($a, $uid, $r);
        $fv = $labs->familyView($pid);
        $this->assertCount(1, $fv);
        $this->assertSame('acima do esperado', $fv[0]['items'][0]['phrase']);
        $this->assertSame('140', $fv[0]['items'][0]['value']);
        $this->assertSame('mg/dL', $fv[0]['items'][0]['unit']);
        $labs->release($b, $uid, $r);
        $labs->delete($b, $uid, $r);
        $this->assertCount(1, $labs->familyView($pid));
        $this->assertCount(1, $labs->collections($pid));
        $this->assertCount(1, $labs->evolution($pid)['collections']);
        $this->assertCount(1, $labs->series($pid, $this->tid('ldl'))['points']);
        $this->assertSame(0, $labs->collections($pid)[0]['attachment_count']);
    }

    public function test_series_band_null_when_range_changes(): void
    {
        $p = $this->setUpPatient();
        $this->add((int) $p['id'], '2026-05-09', ['triglicerides' => '80']);
        $this->add((int) $p['id'], '2026-05-10', ['triglicerides' => '95']);
        $s = Container::labs()->series((int) $p['id'], $this->tid('triglicerides'));
        $this->assertCount(2, $s['points']);
        $this->assertNull($s['band']);

        $q = $this->setUpPatient('2012-01-01');
        $this->add((int) $q['id'], '2023-02-01', ['triglicerides' => '80']);
        $this->add((int) $q['id'], '2023-08-01', ['triglicerides' => '100']);
        $s = Container::labs()->series((int) $q['id'], $this->tid('triglicerides'));
        $this->assertSame(['low' => null, 'high' => 90.0], $s['band']);
        $this->assertSame(80.0, $s['points'][0]['value']);
        $this->assertSame('normal', $s['points'][0]['class']);
    }
}
