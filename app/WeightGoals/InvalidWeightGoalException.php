<?php
declare(strict_types=1);

namespace App\WeightGoals;

/** Meta de peso invalida; `errors` traz mensagens em pt-BR. */
final class InvalidWeightGoalException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
