<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use Tests\DbTestCase;

final class ClinicalEntryRepositoryTest extends DbTestCase
{
    private function repo(): \App\Records\ClinicalEntryRepository
    {
        return Container::clinicalEntries();
    }

    public function testReviseKeepsOldVersionAndReturnsOnlyCurrent(): void
    {
        $p = $this->makePatient();
        $a = $this->makeUser('medico');
        $b = $this->makeUser('medico');
        $id1 = $this->repo()->add((int) $p['id'], 'alergias', ['texto' => 'Amendoim'], null, null, (int) $a['id']);
        $id2 = $this->repo()->revise($id1, ['texto' => 'Amendoim e leite'], null, (int) $b['id']);

        $cur = $this->repo()->currentByKind((int) $p['id'], 'alergias');
        $this->assertCount(1, $cur);
        $this->assertSame($id2, (int) $cur[0]['id']);
        $this->assertSame(2, (int) $cur[0]['version']);
        $this->assertSame('Amendoim e leite', json_decode($cur[0]['payload'], true)['texto']);

        $versions = $this->repo()->versions($id2);
        $this->assertCount(2, $versions);
        $this->assertSame([1, 2], array_map(fn ($v) => (int) $v['version'], $versions));
        $this->assertSame($a['name'], $versions[0]['author_name']);
        $this->assertSame($b['name'], $versions[1]['author_name']);
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM clinical_entries')['c']);
        $this->assertSame('Amendoim', json_decode($this->db->one('SELECT payload FROM clinical_entries WHERE id = ?', [$id1])['payload'], true)['texto']);
        $this->assertNotNull($this->db->one('SELECT superseded_at FROM clinical_entries WHERE id = ?', [$id1])['superseded_at']);
    }

    public function testCannotReviseSupersededEntry(): void
    {
        $p = $this->makePatient();
        $a = $this->makeUser('medico');
        $id1 = $this->repo()->add((int) $p['id'], 'alergias', ['texto' => 'x'], null, null, (int) $a['id']);
        $this->repo()->revise($id1, ['texto' => 'y'], null, (int) $a['id']);
        $this->expectException(\InvalidArgumentException::class);
        $this->repo()->revise($id1, ['texto' => 'z'], null, (int) $a['id']);
    }

    public function testRemoveRequiresReasonAndKeepsRow(): void
    {
        $p = $this->makePatient();
        $a = $this->makeUser('medico');
        $id = $this->repo()->add((int) $p['id'], 'evolucao', ['diagnostico' => 'x'], 'E66.0', '2026-01-10', (int) $a['id']);
        try {
            $this->repo()->remove($id, '   ', (int) $a['id']);
            $this->fail('motivo obrigatorio');
        } catch (\InvalidArgumentException) {
        }
        $this->repo()->remove($id, 'Lançado no paciente errado', (int) $a['id']);
        $this->assertSame([], $this->repo()->evolutions((int) $p['id']));
        $row = $this->db->one('SELECT * FROM clinical_entries WHERE id = ?', [$id]);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame('Lançado no paciente errado', $row['deleted_reason']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE action='delete' AND entity_id = ?", [$id])['c']);
    }

    public function testEvolutionsNewestFirst(): void
    {
        $p = $this->makePatient();
        $a = $this->makeUser('medico');
        $i1 = $this->repo()->add((int) $p['id'], 'evolucao', ['diagnostico' => 'a'], null, '2026-01-01', (int) $a['id']);
        $i2 = $this->repo()->add((int) $p['id'], 'evolucao', ['diagnostico' => 'b'], null, '2026-03-01', (int) $a['id']);
        $ev = $this->repo()->evolutions((int) $p['id']);
        $this->assertSame([$i2, $i1], array_map(fn ($v) => (int) $v['id'], $ev));
    }
}
