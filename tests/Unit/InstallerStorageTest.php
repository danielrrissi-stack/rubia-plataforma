<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Install\Installer;
use PHPUnit\Framework\TestCase;

/** Escolha da pasta de dados conforme o layout da hospedagem (cPanel). */
final class InstallerStorageTest extends TestCase
{
    private string $home;
    private ?string $oldDocRoot;

    protected function setUp(): void
    {
        $this->home = str_replace('\\', '/', sys_get_temp_dir()) . '/rubia_home_' . bin2hex(random_bytes(4));
        mkdir($this->home . '/rubia_app', 0777, true);
        mkdir($this->home . '/public_html/rubia.med.br', 0777, true);
        $this->oldDocRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->oldDocRoot === null) {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $this->oldDocRoot;
        }
        foreach (['/public_html/rubia.med.br', '/public_html', '/rubia_app', ''] as $d) {
            @rmdir($this->home . $d);
        }
    }

    private function choose(string $docRoot): array
    {
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;
        return (new Installer('', $this->home . '/rubia_app'))->chooseStorage();
    }

    private function real(string $p): string
    {
        return str_replace('\\', '/', (string) realpath($p));
    }

    public function test_domain_root_inside_public_html_keeps_data_outside_public_html(): void
    {
        $chosen = $this->choose($this->home . '/public_html/rubia.med.br');
        $this->assertSame($this->real($this->home) . '/rubia_storage', $chosen['path']);
        $this->assertSame('', $chosen['warning']);
    }

    public function test_public_html_as_domain_root_uses_home_folder(): void
    {
        $chosen = $this->choose($this->home . '/public_html');
        $this->assertSame($this->real($this->home) . '/rubia_storage', $chosen['path']);
        $this->assertSame('', $chosen['warning']);
    }
}
