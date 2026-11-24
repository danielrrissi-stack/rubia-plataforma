<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Access\Gate;
use App\Access\PatientScope;
use App\Access\Policy;
use App\Audit\AuditLog;
use App\Core\Container;
use App\Core\HttpException;
use App\Core\Request;
use Tests\DbTestCase;

final class GateTest extends DbTestCase
{
    private function gate(): Gate
    {
        return new Gate(new Policy(), new PatientScope($this->db), new AuditLog($this->db));
    }

    private function req(): Request
    {
        return new Request('GET', '/x', [], [], [], []);
    }

    public function testDenyByPolicyThrows403AndAudits(): void
    {
        $n = $this->makeUser('nutricionista');
        $p = $this->makePatient();
        try {
            $this->gate()->authorize($n, 'view', Policy::CLINICO, (int) $p['id'], $this->req());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status());
        }
        $rows = $this->db->all("SELECT * FROM audit_log WHERE action = 'denied'");
        $this->assertCount(1, $rows);
        $this->assertSame((int) $p['id'], (int) $rows[0]['patient_id']);
        $this->assertSame(Policy::CLINICO, $rows[0]['entity']);
        $this->assertNull($rows[0]['entity_id']);
        $this->assertSame(['acao' => 'view'], json_decode((string) $rows[0]['after_json'], true));
    }

    public function testDenyByScope(): void
    {
        $n = $this->makeUser('nutricionista');
        $p = $this->makePatient();
        $this->assertFalse($this->gate()->check($n, 'view', Policy::ANTROPOMETRIA, (int) $p['id']));
        $this->expectException(HttpException::class);
        $this->gate()->authorize($n, 'view', Policy::ANTROPOMETRIA, (int) $p['id'], $this->req());
    }

    public function testAllowAndNullPatientAndCheckDoesNotAudit(): void
    {
        $m = $this->makeUser('medico');
        $p = $this->makePatient();
        $this->gate()->authorize($m, 'edit', Policy::CLINICO, (int) $p['id'], $this->req());
        $this->gate()->authorize($m, 'view', Policy::CADASTRO_BASICO, null, $this->req());
        $this->assertFalse($this->gate()->check($m, 'view', Policy::AUDITORIA, null));
        $this->assertSame([], $this->db->all("SELECT id FROM audit_log WHERE action = 'denied'"));
        try {
            $this->gate()->authorize($m, 'view', Policy::AUDITORIA, null, $this->req());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status());
        }
        $rows = $this->db->all("SELECT * FROM audit_log WHERE action = 'denied'");
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['patient_id']);
    }

    public function testContainerGetters(): void
    {
        $this->assertInstanceOf(Policy::class, Container::policy());
        $this->assertInstanceOf(PatientScope::class, Container::patientScope());
        $this->assertInstanceOf(Gate::class, Container::gate());
    }
}
