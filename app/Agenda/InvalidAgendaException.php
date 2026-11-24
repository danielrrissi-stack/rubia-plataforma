<?php
declare(strict_types=1);

namespace App\Agenda;

/** Horario, excecao ou consulta invalida; `errors()` traz mensagens em pt-BR. */
final class InvalidAgendaException extends \RuntimeException
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
