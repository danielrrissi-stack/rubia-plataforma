<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Nutrition\PlanText;
use PHPUnit\Framework\TestCase;

final class PlanTextTest extends TestCase
{
    public function test_word_paste_becomes_list_and_titles(): void
    {
        $raw = "CAFÉ DA MANHÃ\r\n\t1 pão francês\r\n• 1 copo de leite\u{00A0}\r\n\r\n\r\n\r\nAlmoço:\r\n1) arroz\r\n2) feijão";
        $html = PlanText::toHtml(PlanText::normalizePaste($raw));
        $this->assertSame('<h3>CAFÉ DA MANHÃ</h3><ul><li>1 pão francês</li><li>1 copo de leite</li></ul><h3>Almoço</h3><ol><li>arroz</li><li>feijão</li></ol>', $html);
    }

    public function test_paragraph_lines_and_bold(): void
    {
        $this->assertSame('<p>Beber <strong>2 litros</strong> de água<br>por dia</p>', PlanText::toHtml("Beber **2 litros** de água\npor dia"));
    }

    public function test_never_outputs_user_html(): void
    {
        $html = PlanText::toHtml(PlanText::normalizePaste("<script>alert(1)</script>\n<b onclick=x>oi</b>\n[a](javascript:alert(1))\n**<img src=x onerror=y>**"));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b ', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('href', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_meal_title_with_colon_and_time(): void
    {
        $this->assertSame('<h3>Café da manhã (7h)</h3><ul><li>1 pão</li></ul>', PlanText::toHtml("Café da manhã (7h):\n• 1 pão"));
        // O que o botao "Titulo de refeicao" insere antes de a pessoa digitar o nome.
        $this->assertSame('<h3>Nome da refeição</h3><ul><li>1 pão</li></ul>', PlanText::toHtml("Nome da refeição:\n• 1 pão"));
    }

    public function test_short_caps_word_is_not_title(): void
    {
        $this->assertSame('<p>OK</p>', PlanText::toHtml('OK'));
    }

    public function test_normalize_bullets_and_invisible_chars(): void
    {
        $this->assertSame("• a\n• b", PlanText::normalizePaste("\u{F0B7}\ta  \n\u{200B}●\u{00A0}b\u{FEFF}"));
    }
}
