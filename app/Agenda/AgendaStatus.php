<?php
declare(strict_types=1);

namespace App\Agenda;

/** Situacoes, tipos e dias da semana da agenda (constantes + funcoes puras). */
final class AgendaStatus
{
    public const STATUSES = ['agendada', 'confirmada', 'realizada', 'faltou', 'cancelada', 'remarcada', 'reservada'];

    public const LABELS = [
        'agendada' => 'Agendada',
        'confirmada' => 'Confirmada',
        'realizada' => 'Realizada',
        'faltou' => 'Faltou',
        'cancelada' => 'Cancelada',
        'remarcada' => 'Remarcada',
        'reservada' => 'Reservada',
    ];

    /** Ocupam horario: todas menos cancelada e remarcada (mesma regra da coluna gerada `occupies`). */
    public const OCCUPYING = ['agendada', 'confirmada', 'realizada', 'faltou', 'reservada'];

    /** Entram nos avisos e e-mails da vespera. */
    public const REMINDABLE = ['agendada', 'confirmada'];

    public const KINDS = ['primeira', 'retorno'];

    public const KIND_LABELS = [
        'primeira' => 'Primeira consulta',
        'retorno' => 'Retorno',
    ];

    /** weekday 1..7 = date('N') (segunda = 1). */
    public const WEEKDAY_LONG = [
        1 => 'segunda-feira',
        2 => 'terça-feira',
        3 => 'quarta-feira',
        4 => 'quinta-feira',
        5 => 'sexta-feira',
        6 => 'sábado',
        7 => 'domingo',
    ];

    public const WEEKDAY_SHORT = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];

    public const DEFAULT_MINUTES = 30;

    /** Horario padrao da clinica: seg-sex 07:30-11:30 e 12:30-20:00; sab 07:30-12:30. */
    public const CLINIC_DEFAULT = [
        ['weekday' => 1, 'start_time' => '07:30', 'end_time' => '11:30'],
        ['weekday' => 1, 'start_time' => '12:30', 'end_time' => '20:00'],
        ['weekday' => 2, 'start_time' => '07:30', 'end_time' => '11:30'],
        ['weekday' => 2, 'start_time' => '12:30', 'end_time' => '20:00'],
        ['weekday' => 3, 'start_time' => '07:30', 'end_time' => '11:30'],
        ['weekday' => 3, 'start_time' => '12:30', 'end_time' => '20:00'],
        ['weekday' => 4, 'start_time' => '07:30', 'end_time' => '11:30'],
        ['weekday' => 4, 'start_time' => '12:30', 'end_time' => '20:00'],
        ['weekday' => 5, 'start_time' => '07:30', 'end_time' => '11:30'],
        ['weekday' => 5, 'start_time' => '12:30', 'end_time' => '20:00'],
        ['weekday' => 6, 'start_time' => '07:30', 'end_time' => '12:30'],
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? $kind;
    }

    public static function occupies(string $status): bool
    {
        return in_array($status, self::OCCUPYING, true);
    }

    public static function remindable(string $status): bool
    {
        return in_array($status, self::REMINDABLE, true);
    }

    /** Dia da semana (1..7, segunda = 1) de uma data 'Y-m-d'. */
    public static function weekday(string $ymd): int
    {
        return (int) (new \DateTimeImmutable($ymd))->format('N');
    }
}
