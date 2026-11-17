<?php
declare(strict_types=1);

namespace App\Labs;

/** Exame ou faixas de referencia invalidos; `errors` traz mensagens em pt-BR citando o exame. */
final class InvalidLabException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
