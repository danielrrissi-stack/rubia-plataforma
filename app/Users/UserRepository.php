<?php
declare(strict_types=1);

namespace App\Users;

use App\Core\Database;

final class UserRepository
{
    private const UPDATABLE = [
        'name', 'email', 'role', 'is_admin', 'professional_register', 'totp_secret',
        'totp_enabled', 'failed_logins', 'locked_until', 'active', 'last_login_at',
    ];

    public function __construct(private Database $db)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [self::normalizeEmail($email)]);
    }

    /** Cria usuario; 'password' (texto puro) opcional vira password_hash. */
    public function create(array $data): int
    {
        if (isset($data['email'])) {
            $data['email'] = self::normalizeEmail((string) $data['email']);
        }
        if (array_key_exists('password', $data)) {
            $plain = $data['password'];
            unset($data['password']);
            $data['password_hash'] = $plain === null ? null : password_hash((string) $plain, PASSWORD_DEFAULT);
        }
        return $this->db->insert('users', $data);
    }

    public function setPassword(int $id, string $plain): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?',
            [password_hash($plain, PASSWORD_DEFAULT), $id]
        );
    }

    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::UPDATABLE));
        if (isset($fields['email'])) {
            $fields['email'] = self::normalizeEmail((string) $fields['email']);
        }
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "`$c` = ?", array_keys($fields)));
        $this->db->run("UPDATE users SET $set WHERE id = ?", [...array_values($fields), $id]);
    }

    public function listStaff(): array
    {
        return $this->db->all(
            "SELECT * FROM users WHERE role IN ('medico','nutricionista','secretaria') ORDER BY name"
        );
    }
}
