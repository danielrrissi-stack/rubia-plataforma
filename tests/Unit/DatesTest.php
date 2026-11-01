<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Dates;
use PHPUnit\Framework\TestCase;

final class DatesTest extends TestCase
{
    public function testParsesBrAndIso(): void
    {
        $this->assertSame('2019-03-05', Dates::parseBr('05/03/2019'));
        $this->assertSame('2019-03-05', Dates::parseBr('2019-03-05'));
    }

    public function testInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dates::parseBr('31/02/2020');
    }

    public function testFormatHelpers(): void
    {
        $this->assertSame('05/03/2019', fmt_date('2019-03-05'));
        $this->assertSame('', fmt_date(null));
        $this->assertSame('32,5', fmt_num(32.5));
        $this->assertSame('-', fmt_num(null));
        $this->assertSame('&lt;a&gt;', e('<a>'));
        $this->assertSame('12', e(12));
    }
}
