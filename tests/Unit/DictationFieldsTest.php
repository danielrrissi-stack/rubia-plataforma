<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Campos curtos de texto livre recebem o microfone com data-ditado (textareas recebem sempre, pelo JS). */
final class DictationFieldsTest extends TestCase
{
    /** um caractere da tag <input>, pulando <?= ... ?> (que contem ">") */
    private const TAG = '(?:<\?=.*?\?>|[^>])';

    private const OPT_IN = [
        'Agenda/views/appointment.php' => ['name="motivo"'],
        'Agenda/views/book.php' => ['name="note"'],
        'Agenda/views/schedule.php' => ['name="reason"'],
        'Diary/views/request_form.php' => ['name="guidance"'],
        'Goals/views/card_form.php' => ['name="title"'],
        'Goals/views/form.php' => ['name="family_text"', 'name="child_text"'],
        'Goals/views/library_form.php' => ['name="family_text"', 'name="child_text"'],
        'Growth/views/index.php' => ['name="motivo"'],
        'Medications/views/dose_form.php' => ['name="reason"'],
        'Medications/views/show.php' => ['name="reason"'],
        'Nutrition/views/edit_text.php' => ['name="titulo"'],
        'Nutrition/views/edit_structured.php' => ['id="pe-titulo"'],
        'Nutrition/views/show.php' => ['name="titulo"', 'name="motivo"'],
        'Nutrition/views/snippet_form.php' => ['name="titulo"'],
        'Nutrition/views/template_form.php' => ['name="titulo"'],
        'Portal/views/diary_entry_form.php' => ['name="activity"'],
        'Portal/views/treatment.php' => ['name="observacao"'],
        'Records/views/index.php' => ['name="motivo"'],
    ];

    public function test_free_text_inputs_opt_in(): void
    {
        foreach (self::OPT_IN as $file => $attrs) {
            $src = (string) file_get_contents(__DIR__ . '/../../app/' . $file);
            foreach ($attrs as $attr) {
                $this->assertMatchesRegularExpression('/<input\b' . self::TAG . '*?' . preg_quote($attr, '/') . self::TAG . '*?\bdata-ditado\b/', $src, $file . ' ' . $attr);
            }
        }
    }

    public function test_codes_names_and_numbers_stay_without_microphone(): void
    {
        $views = glob(__DIR__ . '/../../app/*/views/*.php') ?: [];
        foreach ($views as $v) {
            foreach (preg_split('/(?=<input\b)/', (string) file_get_contents($v)) as $chunk) {
                if (!preg_match('/^<input\b' . self::TAG . '*>/s', $chunk, $tag)) {
                    continue;
                }
                if (str_contains($tag[0], 'data-ditado') && preg_match('/name="(emoji|cid10|email|usuario|q|nome|codigo|dose|frequency|drug_name|peso|altura|alvo)"/', $tag[0])) {
                    $this->fail('Campo que nao e texto livre com microfone em ' . basename($v));
                }
            }
        }
        $this->assertTrue(true);
    }

    public function test_layouts_load_dictation_script(): void
    {
        foreach (['layout.php', 'layout_portal.php'] as $l) {
            $this->assertStringContainsString("asset('/assets/js/dictation.js')", (string) file_get_contents(__DIR__ . '/../../app/Layout/views/' . $l), $l);
        }
    }
}
