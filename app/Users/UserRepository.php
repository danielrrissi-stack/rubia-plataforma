<?php
declare(strict_types=1);

namespace App\Users;

use App\Core\Database;

final class UserRepository
{
    private const UPDATABLE = [
        'name', 'email', 'role', 'is_admin', 'professional_register', 'specialty', 'rqe', 'totp_secret',
        'totp_enabled', 'failed_logins', 'locked_until', 'active', 'last_login_at',
    ];
    private const USERNAME_PATTERN = '/^(?=[a-z0-9.]*[a-z0-9])[a-z0-9.]{3,40}\z/';

    public function __construct(private Database $db)
    {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Nome de usuario em minusculas e sem espacos (o mesmo usado para gravar e para entrar). */
    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $username));
    }

    /** Letras minusculas, numeros e ponto (ao menos uma letra ou numero); 3 a 40; nunca '@' (o que tem '@' e e-mail). */
    public static function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, $username) === 1;
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ?', [self::normalizeEmail($email)]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE username = ?', [self::normalizeUsername($username)]);
    }

    /**
     * Login unico: com '@' procura so por e-mail; sem '@', so por nome de usuario.
     * Assim um usuario igual a parte de um e-mail nunca entra na conta do e-mail.
     */
    public function findByLogin(string $login): ?array
    {
        if (str_contains($login, '@')) {
            return $this->findByEmail($login);
        }
        $username = self::normalizeUsername($login);
        return self::isValidUsername($username) ? $this->findByUsername($username) : null;
    }

    /** Cria usuario; 'password' (texto puro) opcional vira password_hash. */
    public function create(array $data): int
    {
        if (isset($data['email'])) {
            $data['email'] = self::normalizeEmail((string) $data['email']);
        }
        if (isset($data['username'])) {
            $data['username'] = self::normalizeUsername((string) $data['username']);
        }
        if (array_key_exists('password', $data)) {
            $plain = $data['password'];
            unset($data['password']);
            $data['password_hash'] = $plain === null ? null : password_hash((string) $plain, PASSWORD_DEFAULT);
        }
        return $this->db->insert('users', $data);
    }

    /** Senha escolhida pelo proprio usuario: encerra qualquer obrigacao de troca. */
    public function setPassword(int $id, string $plain): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, failed_logins = 0, locked_until = NULL WHERE id = ?',
            [password_hash($plain, PASSWORD_DEFAULT), $id]
        );
    }

    /** Senha provisoria (definida pela equipe): obriga a troca no proximo acesso. */
    public function setTemporaryPassword(int $id, string $plain): void
    {
        $this->db->run(
            'UPDATE users SET password_hash = ?, must_change_password = 1, failed_logins = 0, locked_until = NULL WHERE id = ?',
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
