<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Access\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    private function u(string $role, int $admin = 0, int $active = 1): array
    {
        return ['id' => 1, 'role' => $role, 'is_admin' => $admin, 'active' => $active];
    }

    public function testNutricionista(): void
    {
        $p = new Policy();
        $u = $this->u('nutricionista');
        $this->assertFalse($p->allows($u, 'view', Policy::CLINICO));
        $this->assertFalse($p->allows($u, 'view', Policy::EXAMES));
        $this->assertFalse($p->allows($u, 'view', Policy::MEDICAMENTOS));
        $this->assertTrue($p->allows($u, 'edit', Policy::ENERGIA));
        $this->assertTrue($p->allows($u, 'view', Policy::CADASTRO_BASICO));
        $this->assertFalse($p->allows($u, 'edit', Policy::CADASTRO_BASICO));
    }

    public function testSecretaria(): void
    {
        $p = new Policy();
        $u = $this->u('secretaria');
        $this->assertFalse($p->allows($u, 'view', Policy::ANTROPOMETRIA));
        $this->assertTrue($p->allows($u, 'edit', Policy::CADASTRO));
        $this->assertTrue($p->allows($u, 'view', Policy::AGENDA));
        $this->assertFalse($p->allows($u, 'view', Policy::CLINICO));
    }

    public function testPacienteResponsavelCrianca(): void
    {
        $p = new Policy();
        foreach (['paciente', 'responsavel'] as $r) {
            $u = $this->u($r);
            $this->assertFalse($p->allows($u, 'view', Policy::HISTORICO));
            $this->assertTrue($p->allows($u, 'view', Policy::ANTROPOMETRIA));
            $this->assertTrue($p->allows($u, 'edit', Policy::ANTROPOMETRIA));
            $this->assertFalse($p->allows($u, 'edit', Policy::CADASTRO_BASICO));
        }
        $c = $this->u('crianca');
        $this->assertFalse($p->allows($c, 'view', Policy::CADASTRO_BASICO));
        $this->assertFalse($p->allows($c, 'view', Policy::ANTROPOMETRIA));
    }

    public function testMedicoAndAdmin(): void
    {
        $p = new Policy();
        $m = $this->u('medico');
        $this->assertTrue($p->allows($m, 'edit', Policy::CLINICO));
        $this->assertFalse($p->allows($m, 'view', Policy::AUDITORIA));
        $this->assertFalse($p->allows($m, 'view', Policy::USUARIOS));
        $a = $this->u('medico', 1);
        $this->assertTrue($p->allows($a, 'view', Policy::AUDITORIA));
        $this->assertFalse($p->allows($a, 'edit', Policy::AUDITORIA));
        $this->assertTrue($p->allows($a, 'edit', Policy::USUARIOS));
        $this->assertTrue($p->allows($this->u('secretaria', 1), 'view', Policy::USUARIOS));
    }

    public function testDefaultDeny(): void
    {
        $p = new Policy();
        $this->assertFalse($p->allows($this->u('medico'), 'view', 'inexistente'));
        $this->assertFalse($p->allows($this->u('medico'), 'delete', Policy::CLINICO));
        $this->assertFalse($p->allows($this->u('alien'), 'view', Policy::CADASTRO_BASICO));
        $this->assertFalse($p->allows($this->u('medico', 1, 0), 'view', Policy::CLINICO));
        $this->assertFalse($p->allows(['role' => 'medico'], 'view', Policy::CLINICO));
        $this->assertFalse($p->allows($this->u('crianca', 1), 'view', Policy::CLINICO));
    }
}
