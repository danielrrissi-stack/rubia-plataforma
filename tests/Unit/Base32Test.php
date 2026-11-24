<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Base32;
use PHPUnit\Framework\TestCase;

final class Base32Test extends TestCase
{
    public function testEncodeKnownVector(): void
    {
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Base32::encode('12345678901234567890'));
    }

    public function testRoundtrip(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 19, 20] as $n) {
            $bin = random_bytes($n);
            $this->assertSame($bin, Base32::decode(Base32::encode($bin)));
        }
    }

    public function testDecodeIgnoresCaseSpacesAndPadding(): void
    {
        $this->assertSame('12345678901234567890', Base32::decode('gezd gnbv gy3tqojq gezdgnbvgy3tqojq'));
    }
}
