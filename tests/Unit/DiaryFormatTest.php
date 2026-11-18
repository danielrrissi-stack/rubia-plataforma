<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Diary\DiaryFormat;
use PHPUnit\Framework\TestCase;

final class DiaryFormatTest extends TestCase
{
    public function test_minutes(): void
    {
        $this->assertSame('45 min', DiaryFormat::minutes(45));
        $this->assertSame('1h', DiaryFormat::minutes(60));
        $this->assertSame('1h20', DiaryFormat::minutes(80));
        $this->assertSame('1h05', DiaryFormat::minutes(65));
    }

    public function test_cell_texts(): void
    {
        $this->assertSame('6 copos', DiaryFormat::cell('agua', [['glasses' => 6]], false));
        $this->assertSame('1 copo', DiaryFormat::cell('agua', [['glasses' => 1]], false));
        $this->assertSame('23:00–06:30 😊', DiaryFormat::cell('sono', [['slept_at' => '23:00:00', 'woke_at' => '06:30:00', 'sleep_quality' => 3]], false));
        $this->assertSame('13:00–15:00', DiaryFormat::cell('sono', [['slept_at' => '13:00:00', 'woke_at' => '15:00:00', 'sleep_quality' => null]], false));
        $this->assertSame('1h20 de atividade', DiaryFormat::cell('atividade', [['minutes' => 30], ['minutes' => 50]], false));
        $this->assertSame('1h20 de tela', DiaryFormat::cell('tela', [['minutes' => 80]], false));
        $this->assertSame('3 refeições', DiaryFormat::cell('alimentacao', [[], [], []], false));
        $this->assertSame('1 refeição', DiaryFormat::cell('alimentacao', [[]], false));
        $this->assertSame('pesou', DiaryFormat::cell('medidas', [], true));
        $this->assertNull(DiaryFormat::cell('medidas', [], false));
        $this->assertNull(DiaryFormat::cell('agua', [], false));
    }

    public function test_sleep_range(): void
    {
        $this->assertSame('23:00–06:30', DiaryFormat::sleepRange('23:00', '06:30:00'));
    }
}
