<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Nutrition\FamilyPhrase;
use PHPUnit\Framework\TestCase;

final class FamilyPhraseTest extends TestCase
{
    private function item(array $over): array
    {
        return $over + ['qty' => 1, 'measure_id' => 7, 'measure_name' => 'colher de sopa cheia', 'measure_plural' => 'colheres de sopa cheias',
            'grams' => 25, 'food_name' => 'Arroz, tipo 1, cozido', 'note' => ''];
    }

    public function test_qty(): void
    {
        $this->assertSame('1', FamilyPhrase::qty(1.0));
        $this->assertSame('1,5', FamilyPhrase::qty(1.5));
        $this->assertSame('0,25', FamilyPhrase::qty(0.25));
        $this->assertSame('2', FamilyPhrase::qty(2.0));
    }

    public function test_grams(): void
    {
        $this->assertSame('50 g de Arroz, tipo 1, cozido', FamilyPhrase::item($this->item(['qty' => 50, 'measure_id' => null, 'grams' => 50])));
    }

    public function test_single_measure(): void
    {
        $this->assertSame('1 colher de sopa cheia de Arroz, tipo 1, cozido', FamilyPhrase::item($this->item([])));
    }

    public function test_plural_measure(): void
    {
        $this->assertSame('2 colheres de sopa cheias de Arroz, tipo 1, cozido', FamilyPhrase::item($this->item(['qty' => 2])));
    }

    public function test_missing_plural(): void
    {
        $this->assertSame('2 × colher de sopa cheia de Arroz, tipo 1, cozido', FamilyPhrase::item($this->item(['qty' => 2, 'measure_plural' => null])));
    }

    public function test_note_suffix(): void
    {
        $this->assertSame('1 colher de sopa cheia de Arroz, tipo 1, cozido (sem açúcar)', FamilyPhrase::item($this->item(['note' => 'sem açúcar'])));
    }
}
