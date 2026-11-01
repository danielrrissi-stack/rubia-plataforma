<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use Tests\DbTestCase;

final class MajorityServiceTest extends DbTestCase
{
    private function minor(string $birth): array
    {
        $p = $this->makePatient(['birth_date' => $birth]);
        $g = $this->makeUser('responsavel');
        $this->db->insert('guardian_links', [
            'patient_id' => $p['id'], 'user_id' => $g['id'], 'relationship' => 'mãe', 'created_by' => $p['created_by'],
        ]);
        return $p;
    }

    private function ids(string $today): array
    {
        return array_map(fn($r) => (int) $r['id'], Container::majority()->pending($today));
    }

    private function activeLinks(int $pid): int
    {
        return (int) $this->db->one('SELECT COUNT(*) c FROM guardian_links WHERE patient_id = ? AND active = 1', [$pid])['c'];
    }

    public function testAppearsOnEighteenthBirthdayOnly(): void
    {
        $p = $this->minor('2008-11-01');
        $this->assertContains((int) $p['id'], $this->ids('2026-11-01'));
        $this->assertNotContains((int) $p['id'], $this->ids('2026-10-31'));
    }

    public function testEncerrarDeactivatesLinksAndLeavesList(): void
    {
        $p = $this->minor('2000-01-01');
        $med = $this->makeUser('medico');
        Container::majority()->decide((int) $p['id'], 'encerrar', 'ok', (int) $med['id']);
        $this->assertNotContains((int) $p['id'], $this->ids('2026-11-01'));
        $this->assertSame(0, $this->activeLinks((int) $p['id']));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE entity = 'guardian_links' AND action = 'unlink'")['c']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM audit_log WHERE entity = 'majority_decisions' AND patient_id = ?", [$p['id']])['c']);
    }

    public function testManterKeepsLinks(): void
    {
        $p = $this->minor('2000-01-01');
        $med = $this->makeUser('medico');
        Container::majority()->decide((int) $p['id'], 'manter', null, (int) $med['id']);
        $this->assertNotContains((int) $p['id'], $this->ids('2026-11-01'));
        $this->assertSame(1, $this->activeLinks((int) $p['id']));
    }

    public function testTransferirCreatesPatientLoginAndInvites(): void
    {
        $p = $this->minor('2000-01-01');
        $med = $this->makeUser('medico');
        Container::majority()->decide((int) $p['id'], 'transferir', null, (int) $med['id'], 'novo@example.com');
        $row = $this->db->one('SELECT * FROM patients WHERE id = ?', [$p['id']]);
        $this->assertNotNull($row['user_id']);
        $u = Container::users()->find((int) $row['user_id']);
        $this->assertSame('paciente', $u['role']);
        $this->assertSame(0, $this->activeLinks((int) $p['id']));
    }

    public function testTransferirRejectsEmailOfOtherAccountAndMissingEmail(): void
    {
        $p = $this->minor('2000-01-01');
        $med = $this->makeUser('medico');
        $other = $this->makeUser('secretaria');
        foreach ([$other['email'], null] as $email) {
            try {
                Container::majority()->decide((int) $p['id'], 'transferir', null, (int) $med['id'], $email);
                $this->fail('expected exception');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame(1, $this->activeLinks((int) $p['id']));
        $this->assertContains((int) $p['id'], $this->ids('2026-11-01'));
    }
}
