<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use Tests\DbTestCase;

final class ConsentServiceTest extends DbTestCase
{
    private function term(string $v = '1.0'): void
    {
        $this->db->run('UPDATE consent_terms SET active = 0');
        $this->db->run('DELETE FROM consent_terms WHERE version = ?', [$v]);
        $this->db->insert('consent_terms', ['version' => $v, 'body_html' => '<p>Termo</p>', 'active' => 1]);
    }

    public function testGuardianWithTwoChildrenHasTwoPendingThenOne(): void
    {
        $this->term();
        $g = $this->makeUser('responsavel');
        $p1 = $this->makePatient();
        $p2 = $this->makePatient();
        $by = (int) $p1['created_by'];
        Container::guardians()->link((int) $p1['id'], (int) $g['id'], 'mãe', $by);
        Container::guardians()->link((int) $p2['id'], (int) $g['id'], 'mãe', $by);
        $svc = Container::consents();
        $this->assertCount(2, $svc->pendingFor($g));
        $svc->accept((int) $g['id'], (int) $p1['id'], '1.0', new Request('POST', '/consentimento'));
        $pending = $svc->pendingFor($g);
        $this->assertCount(1, $pending);
        $this->assertSame((int) $p2['id'], (int) $pending[0]['patient_id']);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE action='consent' AND entity='consents'"));
    }

    public function testStaffHasNoPendingAndAdultPatientHasOne(): void
    {
        $this->term();
        $this->assertSame([], Container::consents()->pendingFor($this->makeUser('medico')));
        $pu = $this->makeUser('paciente');
        $this->makePatient(['user_id' => $pu['id']]);
        $this->assertCount(1, Container::consents()->pendingFor($pu));
    }

    public function testAcceptRejectsNonPendingPair(): void
    {
        $this->term();
        $g = $this->makeUser('responsavel');
        $other = $this->makePatient();
        $this->expectException(\InvalidArgumentException::class);
        Container::consents()->accept((int) $g['id'], (int) $other['id'], '1.0', new Request('POST', '/consentimento'));
    }
}
