<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Agenda\Phone;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    public function test_phone_formats(): void
    {
        $this->assertSame('5511987654321', Phone::wa('(11) 98765-4321'));
        $this->assertSame('5511987654321', Phone::wa('+55 11 98765-4321'));
        $this->assertSame('551134567890', Phone::wa('11 3456-7890'));
        $this->assertSame('551134567890', Phone::wa('55 11 3456-7890'));
        $this->assertNull(Phone::wa('98765-4321'), 'sem DDD');
        $this->assertNull(Phone::wa(''));
        $this->assertNull(Phone::wa('   '));
        $this->assertNull(Phone::wa('44 11 98765-4321'), '13 digitos sem 55');
        $this->assertNull(Phone::wa('5511987654321000'));
    }
}
