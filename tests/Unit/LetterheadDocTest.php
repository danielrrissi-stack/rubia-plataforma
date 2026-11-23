<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Letterhead\LetterheadDoc;
use App\Letterhead\PaperType;
use App\Pdf\PdfRenderer;
use PHPUnit\Framework\TestCase;

final class LetterheadDocTest extends TestCase
{
    private const DOCTOR = ['name' => 'Dra. Rúbia Teste', 'specialty' => 'Médica Pediátrica', 'professional_register' => '113058', 'rqe' => '107605'];
    private const HEAD = ['rx_clinic_name' => 'Outro Nome', 'rx_address' => 'Av Orozimbo Maia, 360, Vila Itapura', 'rx_phone' => '(19) 99843-4015', 'rx_city' => 'Campinas - SP'];
    private const PATIENT = ['full_name' => 'Ana Civil', 'social_name' => null, 'birth_date' => '2015-05-10'];

    private function html(array $doctor = [], array $head = [], ?array $patient = self::PATIENT, string $type = 'receita'): string
    {
        return LetterheadDoc::html($type, $doctor + self::DOCTOR, $head + self::HEAD, $patient, '2026-11-23');
    }

    private function text(string $html): string
    {
        $plain = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES, 'UTF-8');
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $plain));
    }

    private function pages(string $html): int
    {
        $renderer = new PdfRenderer(APP_ROOT . '/lib/dompdf', sys_get_temp_dir() . '/rubia-papel-test');
        $pdf = $renderer->render('Papel', $html, LetterheadDoc::cssFile(), LetterheadDoc::fontDir());
        $this->assertStringStartsWith('%PDF-', $pdf);
        return (int) preg_match_all('~/Type\s*/Page\b(?!s)~', $pdf);
    }

    public function test_three_types_with_title(): void
    {
        $this->assertSame(['receita' => 'Receita', 'exames' => 'Pedido de Exames', 'anotacoes' => 'Anotações'], PaperType::TITLES);
        foreach (PaperType::TITLES as $type => $title) {
            $this->assertStringContainsString('<h1 class="pt-titulo">' . $title . '</h1>', $this->html([], [], null, $type));
        }
    }

    public function test_header_comes_from_doctor_and_rx_settings(): void
    {
        $t = $this->text($this->html());
        foreach (['Dra. Rúbia Teste', 'Médica Pediátrica', 'CRM 113058', 'RQE 107605', 'WhatsApp: (19) 99843-4015',
            'Av Orozimbo Maia, 360, Vila Itapura', 'Campinas - SP', 'Site: www.rubiapediatra.com.br', 'Aplicativo: rubia.med.br'] as $x) {
            $this->assertStringContainsString($x, $t);
        }
        // o nome da clinica e parte do desenho
        $this->assertStringNotContainsString('Outro Nome', $t);
    }

    public function test_patient_fields_filled(): void
    {
        $t = $this->text($this->html());
        $this->assertStringContainsString('Ana Civil', $t);
        $this->assertStringContainsString('Data: 23 / 11 / 2026', $t);
        $this->assertStringContainsString('Data de nascimento: 10 / 05 / 2015', $t);
        $this->assertStringContainsString('Bia Social', $this->text($this->html([], [], ['social_name' => 'Bia Social'] + self::PATIENT)));
    }

    public function test_blank_paper_has_no_dates(): void
    {
        $t = $this->text($this->html([], [], null));
        $this->assertStringContainsString('Paciente:', $t);
        $this->assertStringContainsString('Data: / /', $t);
        $this->assertStringContainsString('Data de nascimento: / /', $t);
        $this->assertStringNotContainsString('2026', $t);
    }

    public function test_missing_data_drops_line_and_icon(): void
    {
        $full = substr_count($this->html(), 'pt-icone');
        $h = $this->html(
            ['specialty' => null, 'professional_register' => '', 'rqe' => null],
            ['rx_phone' => '', 'rx_address' => '', 'rx_city' => ''],
            null
        );
        $t = $this->text($h);
        foreach (['CRM', 'RQE', 'WhatsApp'] as $x) {
            $this->assertStringNotContainsString($x, $t);
        }
        $this->assertSame($full - 2, substr_count($h, 'pt-icone'));
    }

    public function test_prefixes_are_not_duplicated(): void
    {
        $t = $this->text($this->html(['professional_register' => 'CRM113058', 'rqe' => 'RQE 107605']));
        $this->assertStringContainsString('CRM113058', $t);
        $this->assertStringNotContainsString('CRM CRM', $t);
        $this->assertStringNotContainsString('RQE RQE', $t);
    }

    public function test_city_already_in_address_is_not_repeated(): void
    {
        $t = $this->text($this->html([], ['rx_address' => 'Av X, 1, Campinas - SP, CEP 13010-211', 'rx_city' => 'Campinas']));
        $this->assertSame(1, substr_count($t, 'Campinas'));
    }

    public function test_escapes_html(): void
    {
        $h = $this->html(['name' => 'Dra <b>X</b>'], ['rx_address' => 'Rua <script>'], ['full_name' => 'Ana & <i>Cia</i>'] + self::PATIENT);
        $this->assertStringContainsString('Dra &lt;b&gt;X', $h);
        $this->assertStringContainsString('Rua &lt;script&gt;', $h);
        $this->assertStringContainsString('Ana &amp; &lt;i&gt;Cia', $h);
    }

    public function test_always_one_page_even_at_the_limits(): void
    {
        $this->assertSame(1, $this->pages($this->html([], [], null)));
        $long = $this->html(
            ['name' => str_repeat('Maria Aparecida ', 12), 'specialty' => str_repeat('Endocrinologia ', 8), 'professional_register' => str_repeat('1', 60)],
            ['rx_address' => str_repeat('Avenida Comprida 123, ', 9), 'rx_city' => str_repeat('Cidade ', 11), 'rx_phone' => str_repeat('9', 40)],
            ['full_name' => str_repeat('MARIA APARECIDA ', 12)] + self::PATIENT
        );
        $this->assertSame(1, $this->pages($long));
    }
}
