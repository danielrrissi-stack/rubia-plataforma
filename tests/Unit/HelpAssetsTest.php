<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Help\Manual;
use PHPUnit\Framework\TestCase;

final class HelpAssetsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../public/assets/manual/';
    private const BUDGET = 8 * 1024 * 1024;

    public function test_every_referenced_image_exists(): void
    {
        $missing = [];
        foreach (Manual::allImages() as $img) {
            if (!is_file(self::DIR . $img['file'])) {
                $missing[] = $img['file'];
            }
        }
        $this->assertSame([], $missing, 'Fotos faltando (rode tools/manual_shots.mjs): ' . implode(', ', $missing));
    }

    public function test_manual_assets_within_budget(): void
    {
        $total = 0;
        if (is_dir(self::DIR)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $total += $f->getSize();
            }
        }
        $this->assertLessThanOrEqual(self::BUDGET, $total, sprintf('public/assets/manual tem %.1f MB', $total / 1048576));
    }
}
