<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use Tests\DbTestCase;

final class PatientRepositoryTest extends DbTestCase
{
    public function testSearchRespectsOnlyIds(): void
    {
        $a = $this->makePatient(['full_name' => 'Ana Zeta Silva']);
        $this->makePatient(['full_name' => 'Bia Zeta Souza']);
        $repo = Container::patients();
        $this->assertCount(2, $repo->search('Zeta', null));
        $ids = array_map('intval', array_column($repo->search('Zeta', [(int) $a['id']]), 'id'));
        $this->assertSame([(int) $a['id']], $ids);
        $this->assertSame([], $repo->search('Zeta', []));
    }

    public function testSearchByCpfDigits(): void
    {
        $a = $this->makePatient(['full_name' => 'Cpf Teste', 'cpf' => '529.982.247-25']);
        $found = Container::patients()->search('52998224725', null);
        $this->assertSame((int) $a['id'], (int) $found[0]['id']);
    }

    public function testUpdateAuditsBeforeAndAfter(): void
    {
        $p = $this->makePatient(['phone' => '111']);
        $u = $this->makeUser('secretaria');
        Container::patients()->update((int) $p['id'], ['phone' => '222'], (int) $u['id']);
        $row = $this->db->one("SELECT * FROM audit_log WHERE entity='patients' AND action='update'");
        $this->assertNotNull($row);
        $this->assertSame((int) $p['id'], (int) $row['patient_id']);
        $this->assertSame('111', json_decode($row['before_json'], true)['phone']);
        $this->assertSame('222', json_decode($row['after_json'], true)['phone']);
        $this->assertSame('222', Container::patients()->find((int) $p['id'])['phone']);
    }

    public function testIsPediatricUsesMonths(): void
    {
        $p = $this->makePatient(['birth_date' => '2008-11-01']);
        $repo = Container::patients();
        $this->assertTrue($repo->isPediatric($p, '2027-12-02'));
        $this->assertFalse($repo->isPediatric($p, '2027-12-04'));
        $this->assertSame(366, $repo->ageInDays(['birth_date' => '2020-01-01'], '2021-01-01'));
    }
}
