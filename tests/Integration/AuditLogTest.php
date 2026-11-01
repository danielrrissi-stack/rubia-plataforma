<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Audit\AuditLog;
use App\Core\Request;
use Tests\DbTestCase;

final class AuditLogTest extends DbTestCase
{
    public function test_record_stores_before_after_and_ip(): void
    {
        $user = $this->makeUser('medico');
        $patient = $this->makePatient();
        $req = new Request('POST', '/x', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'UA-Teste']);
        $log = new AuditLog($this->db);

        $log->record((int) $user['id'], 'update', 'patient', (int) $patient['id'], (int) $patient['id'],
            ['nome' => 'João'], ['nome' => 'José'], $req);
        $log->record(null, 'login_failed', 'user', null, (int) $patient['id']);

        $rows = $log->forPatient((int) $patient['id']);
        $this->assertCount(2, $rows);
        $byAction = array_column($rows, null, 'action');
        $r = $byAction['update'];
        $this->assertSame(['nome' => 'João'], json_decode($r['before_json'], true));
        $this->assertSame(['nome' => 'José'], json_decode($r['after_json'], true));
        $this->assertSame('203.0.113.9', $r['ip']);
        $this->assertSame('UA-Teste', $r['user_agent']);
        $this->assertNull($byAction['login_failed']['before_json']);
        $this->assertNull($byAction['login_failed']['user_id']);
    }
}
