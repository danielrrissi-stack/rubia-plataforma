<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Access\PatientScope;
use Tests\DbTestCase;

final class PatientScopeTest extends DbTestCase
{
    private function scope(): PatientScope
    {
        return new PatientScope($this->db);
    }

    public function testNutricionista(): void
    {
        $n = $this->makeUser('nutricionista');
        $p = $this->makePatient();
        $this->assertFalse($this->scope()->canAccess($n, (int) $p['id']));
        $id = $this->db->insert('professional_access', [
            'patient_id' => $p['id'], 'professional_id' => $n['id'], 'granted_by' => $p['created_by'],
        ]);
        $this->assertTrue($this->scope()->canAccess($n, (int) $p['id']));
        $this->assertSame([(int) $p['id']], $this->scope()->accessiblePatientIds($n));
        $this->db->run('UPDATE professional_access SET revoked_at = NOW() WHERE id = ?', [$id]);
        $this->assertFalse($this->scope()->canAccess($n, (int) $p['id']));
        $this->assertSame([], $this->scope()->accessiblePatientIds($n));
    }

    public function testResponsavelIdor(): void
    {
        $r = $this->makeUser('responsavel');
        $a = $this->makePatient();
        $b = $this->makePatient();
        $lid = $this->db->insert('guardian_links', [
            'patient_id' => $a['id'], 'user_id' => $r['id'], 'relationship' => 'mae', 'created_by' => $a['created_by'],
        ]);
        $this->assertTrue($this->scope()->canAccess($r, (int) $a['id']));
        $this->assertFalse($this->scope()->canAccess($r, (int) $b['id']));
        $this->assertSame([(int) $a['id']], $this->scope()->accessiblePatientIds($r));
        $this->db->run('UPDATE guardian_links SET active = 0 WHERE id = ?', [$lid]);
        $this->assertFalse($this->scope()->canAccess($r, (int) $a['id']));
        $this->assertSame([], $this->scope()->accessiblePatientIds($r));
    }

    public function testPacienteAdultoSoOProprio(): void
    {
        $u = $this->makeUser('paciente');
        $own = $this->makePatient(['user_id' => $u['id']]);
        $other = $this->makePatient();
        $this->assertTrue($this->scope()->canAccess($u, (int) $own['id']));
        $this->assertFalse($this->scope()->canAccess($u, (int) $other['id']));
        $this->assertSame([(int) $own['id']], $this->scope()->accessiblePatientIds($u));
    }

    public function testCriancaNunca(): void
    {
        $u = $this->makeUser('crianca');
        $p = $this->makePatient(['user_id' => $u['id']]);
        $this->assertFalse($this->scope()->canAccess($u, (int) $p['id']));
        $this->assertSame([], $this->scope()->accessiblePatientIds($u));
    }

    public function testMedicoSecretariaAndSoftDelete(): void
    {
        $m = $this->makeUser('medico');
        $s = $this->makeUser('secretaria');
        $p = $this->makePatient();
        $this->assertTrue($this->scope()->canAccess($m, (int) $p['id']));
        $this->assertTrue($this->scope()->canAccess($s, (int) $p['id']));
        $this->assertNull($this->scope()->accessiblePatientIds($m));
        $this->assertNull($this->scope()->accessiblePatientIds($s));
        $this->assertFalse($this->scope()->canAccess($m, 99999999));
        $this->db->run('UPDATE patients SET deleted_at = NOW() WHERE id = ?', [$p['id']]);
        $this->assertFalse($this->scope()->canAccess($m, (int) $p['id']));
        $this->assertFalse($this->scope()->canAccess($s, (int) $p['id']));
    }

    public function testSoftDeletedExcludedForOthersAndInactiveUser(): void
    {
        $n = $this->makeUser('nutricionista');
        $p = $this->makePatient();
        $this->db->insert('professional_access', [
            'patient_id' => $p['id'], 'professional_id' => $n['id'], 'granted_by' => $p['created_by'],
        ]);
        $this->db->run('UPDATE patients SET deleted_at = NOW() WHERE id = ?', [$p['id']]);
        $this->assertFalse($this->scope()->canAccess($n, (int) $p['id']));
        $this->assertSame([], $this->scope()->accessiblePatientIds($n));
        $m = $this->makeUser('medico', ['active' => 0]);
        $q = $this->makePatient();
        $this->assertFalse($this->scope()->canAccess($m, (int) $q['id']));
    }
}
