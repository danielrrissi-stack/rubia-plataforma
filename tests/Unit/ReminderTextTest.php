<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Agenda\ReminderText;
use App\Core\Container;
use Tests\DbTestCase;

/** Texto do aviso (usa o banco so para `settings`). */
final class ReminderTextTest extends DbTestCase
{
    private const ADULT = 'Olá, {nome}! Lembrando da sua consulta com {profissional} {dia}, às {hora}. Endereço: {endereço}. Por favor, confirme sua presença respondendo esta mensagem. Até lá!';
    private const CHILD = 'Olá! Lembrando da consulta de {nome} com {profissional} {dia}, às {hora}. Endereço: {endereço}. Por favor, confirme a presença respondendo esta mensagem. Até lá!';

    private function appt(array $over = []): array
    {
        return array_merge([
            'id' => 1, 'patient_id' => 1, 'professional_id' => 1, 'status' => 'agendada',
            'starts_at' => '2026-11-19 07:30:00', 'ends_at' => '2026-11-19 08:00:00',
            'patient_name' => 'Ana Maria Souza', 'social_name' => null, 'birth_date' => '1990-01-10',
            'patient_phone' => '(11) 98765-4321', 'professional_name' => 'Dra. Rubia Teste',
            'professional_address' => 'Rua A, 10',
        ], $over);
    }

    private function setting(string $key, string $value): void
    {
        $this->db->run('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $value]);
    }

    public function test_adult_default_text_exact(): void
    {
        $this->assertSame(
            'Olá, Ana! Lembrando da sua consulta com Dra. Rubia Teste amanhã, quinta-feira, 19/11, às 07:30. Endereço: Rua A, 10. Por favor, confirme sua presença respondendo esta mensagem. Até lá!',
            Container::reminderText()->render($this->appt(), '2026-11-18'),
        );
        $this->assertSame(['adult' => self::ADULT, 'child' => self::CHILD], Container::reminderText()->templates());
    }

    public function test_child_text_and_social_name(): void
    {
        // 17 anos na data da consulta (faz 18 no dia seguinte): texto de menor
        $child = $this->appt(['patient_name' => 'Pedro Henrique Lima', 'birth_date' => '2008-11-20']);
        $this->assertSame(
            'Olá! Lembrando da consulta de Pedro com Dra. Rubia Teste amanhã, quinta-feira, 19/11, às 07:30. Endereço: Rua A, 10. Por favor, confirme a presença respondendo esta mensagem. Até lá!',
            Container::reminderText()->render($child, '2026-11-18'),
        );
        // 18 anos exatos na data da consulta: adulto
        $adult = $this->appt(['patient_name' => 'Pedro Henrique Lima', 'birth_date' => '2008-11-19']);
        $this->assertStringStartsWith('Olá, Pedro! Lembrando da sua consulta', Container::reminderText()->render($adult, '2026-11-18'));
        // nome social no lugar do primeiro nome
        $social = $this->appt(['social_name' => 'Lu Souza']);
        $this->assertStringStartsWith('Olá, Lu Souza! ', Container::reminderText()->render($social, '2026-11-18'));
    }

    public function test_address_fallback_and_removal(): void
    {
        $noProfAddress = $this->appt(['professional_address' => null]);
        $this->assertSame(
            'Olá, Ana! Lembrando da sua consulta com Dra. Rubia Teste amanhã, quinta-feira, 19/11, às 07:30. Por favor, confirme sua presença respondendo esta mensagem. Até lá!',
            Container::reminderText()->render($noProfAddress, '2026-11-18'),
            'sem endereco: o trecho some sem espaco duplo',
        );
        $this->assertStringNotContainsString('  ', Container::reminderText()->render($this->appt(['professional_address' => '   ']), '2026-11-18'));

        $this->setting('rx_address', 'Av. Clínica, 99');
        $fresh = new ReminderText($this->db);
        $this->assertStringContainsString('às 07:30. Endereço: Av. Clínica, 99. Por favor', $fresh->render($noProfAddress, '2026-11-18'));
        $this->assertStringContainsString('Endereço: Rua A, 10.', $fresh->render($this->appt(), '2026-11-18'), 'endereco da profissional vence');
    }

    public function test_day_phrase(): void
    {
        $this->assertSame('hoje, quinta-feira, 19/11', ReminderText::dayPhrase('2026-11-19', '2026-11-19'));
        $this->assertSame('amanhã, quinta-feira, 19/11', ReminderText::dayPhrase('2026-11-19', '2026-11-18'));
        $this->assertSame('quinta-feira, 19/11', ReminderText::dayPhrase('2026-11-19', '2026-11-17'));
        $this->assertSame('sábado, 02/01', ReminderText::dayPhrase('2027-01-02', '2026-12-30'));
        $this->assertSame('amanhã, sexta-feira, 01/01', ReminderText::dayPhrase('2027-01-01', '2026-12-31'), 'virada de ano');
    }

    public function test_custom_template_from_settings(): void
    {
        $this->setting('agenda_text_adult', 'Oi {nome}, {profissional} te espera {dia} às {hora} ({endereço}). {outro}');
        $this->setting('agenda_text_child', '');
        $rt = new ReminderText($this->db);
        $this->assertSame(
            'Oi Ana, Dra. Rubia Teste te espera hoje, quinta-feira, 19/11 às 07:30 (Rua A, 10). {outro}',
            $rt->render($this->appt(), '2026-11-19'),
        );
        $this->assertSame(self::CHILD, $rt->templates()['child'], 'vazio = padrao');
        // sem endereco num texto proprio: o placeholder vira vazio
        $this->assertSame(
            'Oi Ana, Dra. Rubia Teste te espera quinta-feira, 19/11 às 07:30 (). {outro}',
            $rt->render($this->appt(['professional_address' => null]), '2026-11-10'),
        );
    }
}
