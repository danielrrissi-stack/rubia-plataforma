<?php
declare(strict_types=1);

namespace App\Goals;

/** Meta invalida ou marcacao nao permitida; `errors` traz mensagens em pt-BR. */
final class InvalidGoalException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
