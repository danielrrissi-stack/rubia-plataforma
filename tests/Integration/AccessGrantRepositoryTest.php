<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use Tests\DbTestCase;

final class AccessGrantRepositoryTest extends DbTestCase
{
    public function testDuplicateGrantCreatesSingleRow(): void
    {
        $med = $this->makeUser('medico');
        $nut = $this->makeUser('nutricionista');
        $p = $this->makePatient();
        $a = Container::accessGrants()->grant((int) $p['id'], (int) $nut['id'], (int) $med['id']);
        $b = Container::accessGrants()->grant((int) $p['id'], (int) $nut['id'], (int) $med['id']);
        $this->assertSame($a, $b);
        $this->assertCount(1, Container::accessGrants()->activeFor((int) $p['id']));
        $this->assertTrue(Container::patientScope()->canAccess($nut, (int) $p['id']));
    }

    public function testRevokeEndsAccessAndAudits(): void
    {
        $med = $this->makeUser('medico');
        $nut = $this->makeUser('nutricionista');
        $p = $this->makePatient();
        $id = Container::accessGrants()->grant((int) $p['id'], (int) $nut['id'], (int) $med['id']);
        Container::accessGrants()->revoke($id, (int) $med['id']);
        $row = $this->db->one('SELECT * FROM professional_access WHERE id = ?', [$id]);
        $this->assertNotNull($row['revoked_at']);
        $this->assertFalse(Container::patientScope()->canAccess($nut, (int) $p['id']));
        $this->assertSame([], Container::accessGrants()->activeFor((int) $p['id']));
        $actions = array_column($this->db->all('SELECT action FROM audit_log WHERE entity = ?', ['professional_access']), 'action');
        $this->assertContains('grant', $actions);
        $this->assertContains('revoke', $actions);
    }
}
