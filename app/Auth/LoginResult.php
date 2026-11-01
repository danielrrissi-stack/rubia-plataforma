<?php
declare(strict_types=1);

namespace App\Auth;

final class LoginResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $needsTotp = false,
        public readonly ?string $error = null,
    ) {
    }
}
