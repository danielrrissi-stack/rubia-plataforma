<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** .cartao (pagina do paciente e demais telas): caixa propria com faixa colorida no titulo; .cartoes em grade com espaco entre eles. */
final class CardStyleTest extends TestCase
{
    public function test_card_is_a_box_with_header_band(): void
    {
        $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/app.css');
        $this->assertMatchesRegularExpression('/\.cartao\s*\{[^}]*border-left:[^}]*\}/', $css);
        $this->assertMatchesRegularExpression('/\.cartao > h2:first-child\s*\{[^}]*background:[^}]*border-bottom:/', $css);
        $this->assertMatchesRegularExpression('/\.cartoes\s*\{[^}]*display: grid[^}]*gap:/', $css);
        // o estilo do prontuario vem depois e continua valendo
        $this->assertLessThan(strpos($css, '.pront-item {'), strpos($css, '.cartao {'));
    }
}
