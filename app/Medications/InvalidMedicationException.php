<?php
declare(strict_types=1);

namespace App\Medications;

/** Prescricao, dose ou registro invalido; `errors()` traz mensagens em pt-BR. */
final class InvalidMedicationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(private array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
