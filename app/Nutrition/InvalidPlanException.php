<?php
declare(strict_types=1);

namespace App\Nutrition;

/** Plano estruturado invalido; `errors` traz mensagens em pt-BR citando refeicao/opcao/item. */
final class InvalidPlanException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
