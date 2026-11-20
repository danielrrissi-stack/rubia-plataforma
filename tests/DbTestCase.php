<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Container;
use App\Core\Mailer;
use App\Core\Session;
use App\Core\Database;
use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase
{
    protected Database $db;
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new Database((array) Config::get('db'));
        $this->db->pdo()->beginTransaction();
        Container::reset();
        Container::set('db', $this->db);
        Session::reset();
        Mailer::resetSent();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        parent::tearDown();
    }

    protected function makeUser(string $role, array $over = []): array
    {
        $n = ++self::$seq;
        $row = array_merge([
            'name' => 'Usuario ' . $n,
            'email' => 'user' . $n . '_' . bin2hex(random_bytes(3)) . '@example.test',
            'password_hash' => password_hash('senha-teste-123', PASSWORD_DEFAULT),
            'role' => $role,
            'active' => 1,
        ], $over);
        $id = $this->db->insert('users', $row);
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    protected function makePatient(array $over = []): array
    {
        if (!isset($over['responsible_professional_id']) || !isset($over['created_by'])) {
            $medico = $this->makeUser('medico');
            $over['responsible_professional_id'] ??= $medico['id'];
            $over['created_by'] ??= $medico['id'];
        }
        $n = ++self::$seq;
        $row = array_merge([
            'full_name' => 'Paciente ' . $n,
            'birth_date' => '2015-03-10',
            'sex' => 'F',
            'phone' => '11999990000',
        ], $over);
        $id = $this->db->insert('patients', $row);
        return $this->db->one('SELECT * FROM patients WHERE id = ?', [$id]);
    }
}
