<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/manual_shots_list.php';

final class HelpShotListTest extends TestCase
{
    public function test_placeholders_are_resolved(): void
    {
        $list = manualShotList([['file' => 'medica/x.jpg', 'url' => '/pacientes/{adulto}?d={dia_agenda}', 'as' => 'medico', 'width' => 'desktop', 'role' => 'medico']],
            ['adulto' => 7, 'dia_agenda' => '2026-11-20']);
        $this->assertSame([['file' => 'medica/x.jpg', 'url' => '/pacientes/7?d=2026-11-20', 'as' => 'medico', 'width' => 'desktop']], $list);
    }

    public function test_missing_placeholder_names_the_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Marcador {coleta} sem valor (arquivo medica/y.jpg)');
        manualShotList([['file' => 'medica/y.jpg', 'url' => '/exames/{coleta}', 'as' => 'medico', 'width' => 'desktop']], []);
    }

    public function test_same_file_twice_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $img = ['file' => 'medica/z.jpg', 'url' => '/', 'as' => 'medico', 'width' => 'desktop'];
        manualShotList([$img, $img], []);
    }
}
