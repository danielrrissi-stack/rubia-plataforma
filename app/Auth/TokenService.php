<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/** Tokens de uso unico (convite/reset). Guarda apenas o hash SHA-256. */
final class TokenService
{
    public function __construct(private Database $db)
    {
    }

    public function issue(int $userId, string $purpose, int $ttlMinutes): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run(
            'INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [$userId, $purpose, hash('sha256', $token), $ttlMinutes]
        );
        return $token;
    }

    /** Devolve o id do usuario e invalida o token; null se invalido, usado, expirado ou de outro proposito. */
    public function consume(string $token, string $purpose): ?int
    {
        $row = $this->db->one(
            'SELECT id, user_id FROM user_tokens
             WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()',
            [hash('sha256', $token), $purpose]
        );
        if ($row === null) {
            return null;
        }
        $upd = $this->db->run('UPDATE user_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$row['id']]);
        return $upd->rowCount() === 1 ? (int) $row['user_id'] : null;
    }

    /** Verifica sem consumir (para exibir o formulario). */
    public function peek(string $token, string $purpose): ?int
    {
        $row = $this->db->one(
            'SELECT user_id FROM user_tokens
             WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()',
            [hash('sha256', $token), $purpose]
        );
        return $row === null ? null : (int) $row['user_id'];
    }
}
