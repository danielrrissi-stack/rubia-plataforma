<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Diary\DiaryPhotoStore;
use App\Diary\InvalidDiaryException;
use PHPUnit\Framework\TestCase;

final class DiaryPhotoStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rubia_dp_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/uploads', 0777, true);
        mkdir($this->dir . '/in', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$d/$f") ? $this->rrmdir("$d/$f") : @unlink("$d/$f");
            }
        }
        @rmdir($d);
    }

    private function store(): DiaryPhotoStore
    {
        return new DiaryPhotoStore($this->dir . '/uploads', true);
    }

    private function upload(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $tmp = $this->dir . '/in/' . bin2hex(random_bytes(5));
        file_put_contents($tmp, $content);
        return ['name' => $name, 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($content)];
    }

    private function rejects(array $file, string $message): void
    {
        try {
            $this->store()->store($file);
            $this->fail('Esperava InvalidDiaryException');
        } catch (InvalidDiaryException $e) {
            $this->assertStringContainsString($message, implode(' ', $e->errors()));
        }
        $this->assertSame([], glob($this->dir . '/uploads/diario/*/*/*') ?: []);
    }

    public function test_rejects_pdf_disguised_and_oversize_and_upload_error(): void
    {
        $this->rejects($this->upload('foto.jpg', "%PDF-1.4\n%%EOF"), 'Envie uma foto em JPEG, PNG ou WebP.');
        $this->rejects($this->upload('foto.jpg', str_repeat('a', 10 * 1024 * 1024 + 1)), 'maior que o permitido');
        $this->rejects($this->upload('foto.jpg', '', UPLOAD_ERR_INI_SIZE), 'maior que o permitido');
        $this->rejects($this->upload('foto.jpg', '', UPLOAD_ERR_PARTIAL), 'Não foi possível receber a foto');
    }

    public function test_keeps_original_without_gd(): void
    {
        if (extension_loaded('gd')) {
            $this->markTestSkipped('GD carregada.');
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $res = $this->store()->store($this->upload('x.png', $png));
        $this->assertSame('image/png', $res['mime']);
        $this->assertSame(strlen($png), $res['size']);
        $this->assertMatchesRegularExpression('#^diario/\d{4}/\d{2}/[0-9a-f]{32}\.png$#', $res['path']);
        $this->assertFileExists($this->dir . '/uploads/' . $res['path']);
    }

    public function test_resizes_with_gd(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD não carregada.');
        }
        $img = imagecreatetruecolor(3000, 1000);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $res = $this->store()->store($this->upload('x.png', $png));
        $this->assertSame('image/jpeg', $res['mime']);
        $this->assertStringEndsWith('.jpg', $res['path']);
        [$w, $h] = getimagesize($this->dir . '/uploads/' . $res['path']);
        $this->assertSame(1280, $w);
        $this->assertSame(427, $h);
    }

    public function test_discard_and_response(): void
    {
        if (extension_loaded('gd')) {
            $this->markTestSkipped('Usa o original sem GD.');
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $res = $this->store()->store($this->upload('x.png', $png));
        $r = $this->store()->response(['photo_path' => $res['path'], 'photo_mime' => $res['mime']]);
        $this->assertSame(200, $r->status);
        $this->assertSame('private, no-store', $r->headers['Cache-Control']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->store()->discard($res['path']);
        $this->assertFileDoesNotExist($this->dir . '/uploads/' . $res['path']);
        $this->assertSame(404, $this->store()->response(['photo_path' => $res['path'], 'photo_mime' => 'image/png'])->status);
    }
}
