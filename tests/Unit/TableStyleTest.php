<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** .tabela: cabecalho com faixa, linha entre registros e fundo alternado (no <tr>, para nao cobrir as cores das celulas dos exames). */
final class TableStyleTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(APP_ROOT . '/public/assets/css/app.css');
    }

    public function test_table_has_header_band_row_lines_and_zebra(): void
    {
        $css = $this->css();
        $this->assertMatchesRegularExpression('/\.tabela thead th\s*\{[^}]*background:/', $css);
        $this->assertMatchesRegularExpression('/\.tabela (th|td)[^{]*\{[^}]*border-bottom:/', $css);
        $this->assertMatchesRegularExpression('/\.tabela tbody tr:nth-child\(even\)\s*\{[^}]*background:/', $css);
        $this->assertDoesNotMatchRegularExpression('/nth-child\(even\)\s+td/', $css);
    }

    public function test_patient_list_scrolls_on_phone(): void
    {
        $src = (string) file_get_contents(APP_ROOT . '/app/Patients/views/index.php');
        $this->assertStringContainsString('<div class="tabela-rolagem"><table class="tabela">', $src);
    }
}
