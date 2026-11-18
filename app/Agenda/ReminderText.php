<?php
declare(strict_types=1);

namespace App\Agenda;

use App\Core\Database;
use App\Growth\Age;

/**
 * Texto do aviso de consulta (WhatsApp e e-mails) e os contatos de WhatsApp da consulta.
 * Textos configuraveis em `settings` (agenda_text_adult / agenda_text_child; vazio = padrao).
 * "Hoje" chega por parametro ('Y-m-d').
 */
final class ReminderText
{
    public const KEYS = ['adult' => 'agenda_text_adult', 'child' => 'agenda_text_child'];

    public const DEFAULTS = [
        'adult' => 'Olá, {nome}! Lembrando da sua consulta com {profissional} {dia}, às {hora}. Endereço: {endereço}. Por favor, confirme sua presença respondendo esta mensagem. Até lá!',
        'child' => 'Olá! Lembrando da consulta de {nome} com {profissional} {dia}, às {hora}. Endereço: {endereço}. Por favor, confirme a presença respondendo esta mensagem. Até lá!',
    ];

    private const ADDRESS_SEGMENT = 'Endereço: {endereço}.';

    /** @var array<string,string>|null settings lidos uma vez por instancia */
    private ?array $settings = null;

    public function __construct(private Database $db)
    {
    }

    /** @return array{adult:string,child:string} textos em uso (gravados ou padrao) */
    public function templates(): array
    {
        $s = $this->settings();
        $out = [];
        foreach (self::KEYS as $kind => $key) {
            $v = trim($s[$key] ?? '');
            $out[$kind] = $v === '' ? self::DEFAULTS[$kind] : $v;
        }
        return $out;
    }

    /** Aviso pronto. $appointment como AppointmentRepository::find; menor = < 18 anos na data da consulta. */
    public function render(array $appointment, string $today): string
    {
        $startsAt = (string) $appointment['starts_at'];
        $day = substr($startsAt, 0, 10);
        $minor = Age::years((string) $appointment['birth_date'], $day) < 18;
        $template = $this->templates()[$minor ? 'child' : 'adult'];

        $address = $this->address($appointment);
        if ($address === '') {
            $template = str_replace([' ' . self::ADDRESS_SEGMENT, self::ADDRESS_SEGMENT], '', $template);
        }

        return strtr($template, [
            '{nome}' => self::callName($appointment),
            '{profissional}' => (string) $appointment['professional_name'],
            '{dia}' => self::dayPhrase($day, $today),
            '{hora}' => substr($startsAt, 11, 5),
            '{endereço}' => $address,
        ]);
    }

    /** Endereco da profissional, senao settings.rx_address, senao ''. */
    public function address(array $appointment): string
    {
        $address = trim((string) ($appointment['professional_address'] ?? ''));
        return $address !== '' ? $address : trim($this->settings()['rx_address'] ?? '');
    }

    /** "amanhã, quinta-feira, 19/11" / "hoje, quinta-feira, 19/11" / "quinta-feira, 19/11". */
    public static function dayPhrase(string $apptYmd, string $today): string
    {
        $d = new \DateTimeImmutable($apptYmd);
        $base = AgendaStatus::WEEKDAY_LONG[(int) $d->format('N')] . ', ' . $d->format('d/m');
        if ($apptYmd === $today) {
            return 'hoje, ' . $base;
        }
        if ($apptYmd === (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')) {
            return 'amanhã, ' . $base;
        }
        return $base;
    }

    /**
     * Telefone do paciente ("WhatsApp — paciente") e celular de cada responsavel ativo (vinculo e conta ativos) ("WhatsApp — {nome}").
     * `wa` = numero para wa.me ou null quando o formato e invalido. Sem telefone nenhum = lista vazia.
     *
     * @return list<array{label:string,wa:?string,raw:string}>
     */
    public function contacts(array $appointment): array
    {
        $out = [];
        $own = trim((string) ($appointment['patient_phone'] ?? ''));
        if ($own !== '') {
            $out[] = ['label' => 'WhatsApp — paciente', 'wa' => Phone::wa($own), 'raw' => $own];
        }
        $guardians = $this->db->all(
            "SELECT u.name, u.phone FROM guardian_links g JOIN users u ON u.id = g.user_id
             WHERE g.patient_id = ? AND g.active = 1 AND u.active = 1 AND u.phone IS NOT NULL AND TRIM(u.phone) <> '' ORDER BY g.id",
            [(int) $appointment['patient_id']],
        );
        foreach ($guardians as $g) {
            $raw = trim((string) $g['phone']);
            $out[] = ['label' => 'WhatsApp — ' . $g['name'], 'wa' => Phone::wa($raw), 'raw' => $raw];
        }
        return $out;
    }

    /** Nome social, senao o primeiro nome. */
    public static function callName(array $a): string
    {
        $social = trim((string) ($a['social_name'] ?? ''));
        if ($social !== '') {
            return $social;
        }
        $parts = preg_split('/\s+/u', trim((string) $a['patient_name'])) ?: [''];
        return (string) $parts[0];
    }

    /** @return array<string,string> */
    private function settings(): array
    {
        if ($this->settings === null) {
            $this->settings = [];
            $rows = $this->db->all(
                'SELECT `key`, value FROM settings WHERE `key` IN (?, ?, ?)',
                [self::KEYS['adult'], self::KEYS['child'], 'rx_address'],
            );
            foreach ($rows as $row) {
                $this->settings[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }
        return $this->settings;
    }

    /** Depois de gravar os textos (mesma instancia no Container). */
    public function forget(): void
    {
        $this->settings = null;
    }
}
