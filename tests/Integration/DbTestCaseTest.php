<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\DbTestCase;

final class DbTestCaseTest extends DbTestCase
{
    public function test_helpers_create_rows_and_roll_back(): void
    {
        $p = $this->makePatient();
        $u = $this->makeUser('nutricionista');
        $this->assertTrue(password_verify('senha-teste-123', $u['password_hash']));
        $this->assertSame('medico', $this->db->one('SELECT role FROM users WHERE id=?', [$p['responsible_professional_id']])['role']);
        $this->assertGreaterThan(0, (int) $p['id']);
    }

    public function test_previous_test_rolled_back(): void
    {
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM patients')['c']);
    }
}
