<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Install\Requirements;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase
{
    public function test_reports_php_version_item(): void
    {
        $items = Requirements::check(sys_get_temp_dir());
        $php = null;
        foreach ($items as $i) {
            if (str_starts_with($i['item'], 'PHP')) {
                $php = $i;
                break;
            }
        }
        $this->assertNotNull($php);
        $this->assertTrue($php['ok']);
        $this->assertTrue($php['required']);
        foreach ($items as $i) {
            $this->assertSame(['item', 'ok', 'required', 'detail'], array_keys($i));
        }
    }

    public function test_blocking_failure_detected(): void
    {
        $items = Requirements::check(__FILE__ . '/sub/dir');
        $this->assertFalse(Requirements::passes($items));
        $this->assertTrue(Requirements::passes([['item' => 'x', 'ok' => false, 'required' => false, 'detail' => '']]));
    }
}
