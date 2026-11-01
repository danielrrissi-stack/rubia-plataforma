<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoload_and_config(): void
    {
        $this->assertSame('America/Sao_Paulo', date_default_timezone_get());
        $this->assertTrue(class_exists(\App\Core\Config::class));
    }
}
