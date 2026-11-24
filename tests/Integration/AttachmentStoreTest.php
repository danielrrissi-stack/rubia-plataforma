<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Labs\AttachmentStore;
use App\Labs\InvalidLabException;
use Tests\DbTestCase;

final class AttachmentStoreTest extends DbTestCase
{
    private const PDF = "%PDF-1.4\n%%EOF";

    private string $storage;
    private string $uploads;
    private string $incoming;
    private int $uid;
    private int $patientId;
    private int $collectionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/rubia_att_' . bin2hex(random_bytes(4));
        $this->uploads = $this->storage . '/uploads';
        $this->incoming = $this->storage . '/incoming';
        mkdir($this->uploads, 0777, true);
        mkdir($this->incoming, 0777, true);

        $medico = $this->makeUser('medico');
        $this->uid = (int) $medico['id'];
        $p = $this->makePatient(['created_by' => $this->uid, 'responsible_professional_id' => $this->uid]);
        $this->patientId = (int) $p['id'];
        $glicemia = (int) Container::labCatalog()->findByCode('glicemia')['id'];
        $this->collectionId = Container::labs()->create(
            $this->patientId,
            ['collected_on' => '2026-05-10'],
            [$glicemia => '98'],
            $this->uid,
            new Request('POST', '/'),
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->storage);
        parent::tearDown();
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$d/$f") ? $this->rrmdir("$d/$f") : @unlink("$d/$f");
        }
        @rmdir($d);
    }

    private function store(): AttachmentStore
    {
        return new AttachmentStore($this->db, Container::audit(), $this->uploads, true);
    }

    /** Arquivo no formato de $_FILES['x'] com o conteudo dado. */
    private function upload(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $tmp = $this->incoming . '/' . bin2hex(random_bytes(6));
        file_put_contents($tmp, $content);
        return ['name' => $name, 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($content)];
    }

    /** Monta o formato multi-arquivo do PHP (name[]). */
    private function multi(array ...$files): array
    {
        $out = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($files as $f) {
            foreach ($out as $k => $_) {
                $out[$k][] = $f[$k];
            }
        }
        return $out;
    }

    /** @return list<string> arquivos gravados sob uploads */
    private function storedFiles(): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploads, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $found[] = $f->getPathname();
            }
        }
        return $found;
    }

    private function rows(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) n FROM lab_attachments')['n'];
    }

    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (InvalidLabException $e) {
            return $e->errors;
        }
        $this->fail('Deveria lançar InvalidLabException.');
    }

    public function test_rejects_disguised_file(): void
    {
        $f = $this->upload('laudo.pdf', '<?php echo 1;');
        $errs = $this->errors(fn() => $this->store()->store($this->collectionId, $this->patientId, $f, $this->uid, new Request('POST', '/')));
        $this->assertContains('Envie o laudo em PDF, JPG ou PNG.', $errs);
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->rows());
    }

    public function test_rejects_over_10mb(): void
    {
        $content = '%PDF-1.4' . "\n" . str_repeat('A', 10 * 1024 * 1024 + 1 - 9);
        $this->assertSame(10 * 1024 * 1024 + 1, strlen($content));
        $f = $this->upload('grande.pdf', $content);
        $errs = $this->errors(fn() => $this->store()->store($this->collectionId, $this->patientId, $f, $this->uid, new Request('POST', '/')));
        $this->assertSame(['O arquivo grande.pdf é maior que o permitido (máx. 10 MB).'], $errs);
        $this->assertSame(0, $this->rows());
    }

    public function test_rejects_php_upload_errors(): void
    {
        $f = $this->upload('enorme.pdf', '', UPLOAD_ERR_INI_SIZE);
        $errs = $this->errors(fn() => $this->store()->store($this->collectionId, $this->patientId, $f, $this->uid, new Request('POST', '/')));
        $this->assertSame(['O arquivo enorme.pdf é maior que o permitido (máx. ' . \App\Labs\AttachmentStore::limitMegabytes(ini_get('upload_max_filesize')) . ' MB).'], $errs);
        $f = $this->upload('parcial.pdf', self::PDF, UPLOAD_ERR_PARTIAL);
        $this->assertNotEmpty($this->errors(fn() => $this->store()->store($this->collectionId, $this->patientId, $f, $this->uid, new Request('POST', '/'))));
        $this->assertSame(0, $this->rows());
    }

    public function test_refuses_non_uploaded_files_by_default(): void
    {
        $f = $this->upload('laudo.pdf', self::PDF);
        $strict = new AttachmentStore($this->db, Container::audit(), $this->uploads);
        $this->assertNotEmpty($this->errors(fn() => $strict->store($this->collectionId, $this->patientId, $f, $this->uid, new Request('POST', '/'))));
        $this->assertFileExists($f['tmp_name']);
        $this->assertSame(0, $this->rows());
    }

    public function test_rejects_collection_of_other_patient(): void
    {
        $other = $this->makePatient();
        $f = $this->upload('laudo.pdf', self::PDF);
        $this->assertNotEmpty($this->errors(fn() => $this->store()->store($this->collectionId, (int) $other['id'], $f, $this->uid, new Request('POST', '/'))));
        $this->assertSame(0, $this->rows());
    }

    public function test_store_many_is_all_or_nothing(): void
    {
        $files = $this->multi($this->upload('bom.pdf', self::PDF), $this->upload('falso.pdf', '<?php echo 1;'));
        $errs = $this->errors(fn() => $this->store()->storeMany($this->collectionId, $this->patientId, $files, $this->uid, new Request('POST', '/')));
        $this->assertContains('Envie o laudo em PDF, JPG ou PNG.', $errs);
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->rows());
    }

    public function test_store_many_ignores_empty_slots_and_stores_all(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . "\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x02\0\0\0\x90wS\xde";
        $empty = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $files = $this->multi($this->upload('a.pdf', self::PDF), $empty, $this->upload('foto.png', $png));
        $ids = $this->store()->storeMany($this->collectionId, $this->patientId, $files, $this->uid, new Request('POST', '/'));
        $this->assertCount(2, $ids);
        $this->assertSame('image/png', $this->store()->find($ids[1])['mime']);
        $this->assertStringEndsWith('.png', $this->store()->find($ids[1])['stored_path']);
        $this->assertCount(2, $this->storedFiles());
        $this->assertSame([], $this->store()->storeMany($this->collectionId, $this->patientId, $this->multi($empty), $this->uid, new Request('POST', '/')));
    }

    public function test_store_many_removes_moved_files_when_insert_fails(): void
    {
        $files = $this->multi($this->upload('a.pdf', self::PDF), $this->upload('b.pdf', self::PDF));
        // usuario inexistente quebra a FK uploaded_by depois que os arquivos ja foram movidos
        try {
            $this->store()->storeMany($this->collectionId, $this->patientId, $files, 99999999, new Request('POST', '/'));
            $this->fail('Deveria falhar no INSERT.');
        } catch (\PDOException) {
        }
        $this->assertSame(0, $this->rows());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_stores_pdf_outside_public_and_downloads(): void
    {
        $f = $this->upload("../pasta/laudo mar\x01ço.pdf", self::PDF);
        $id = $this->store()->store($this->collectionId, $this->patientId, $f, $this->uid, new Request('POST', '/'));

        $row = $this->db->one('SELECT * FROM lab_attachments WHERE id = ?', [$id]);
        $this->assertSame('application/pdf', $row['mime']);
        $this->assertSame(hash('sha256', self::PDF), $row['sha256']);
        $this->assertSame(strlen(self::PDF), (int) $row['size_bytes']);
        $this->assertSame('laudo março.pdf', $row['original_name']);
        $this->assertMatchesRegularExpression('#^exames/\d{4}/\d{2}/[0-9a-f]{32}\.pdf$#', $row['stored_path']);
        $this->assertSame((int) $this->uid, (int) $row['uploaded_by']);
        $this->assertFileExists($this->uploads . '/' . $row['stored_path']);

        $att = $this->store()->find($id);
        $this->assertSame($this->patientId, (int) $att['patient_id']);
        $a = $this->db->one("SELECT * FROM audit_log WHERE entity = 'lab_attachments' AND entity_id = ? AND action = 'upload'", [$id]);
        $this->assertNotNull($a);
        $this->assertSame($this->patientId, (int) $a['patient_id']);

        $res = $this->store()->downloadResponse($att);
        $this->assertSame('application/pdf', $res->headers['Content-Type']);
        $this->assertSame('attachment; filename="laudo março.pdf"', $res->headers['Content-Disposition']);
        $this->assertSame('private, no-store', $res->headers['Cache-Control']);
        $this->assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        $this->assertSame(realpath($this->uploads . '/' . $row['stored_path']), $res->filePath);
    }

    public function test_original_name_is_sanitized(): void
    {
        $f = $this->upload('x.pdf', self::PDF);
        $f['name'] = "C:\\Users\\fulano\\exa\x00me\x1f" . str_repeat('é', 300) . '.pdf';
        $id = $this->store()->store($this->collectionId, $this->patientId, $f, $this->uid, new Request('POST', '/'));
        $name = $this->store()->find($id)['original_name'];
        $this->assertSame(200, mb_strlen($name));
        $this->assertStringStartsWith('exame', $name);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F\\\\\/]/', $name);
    }

    public function test_delete_is_logical(): void
    {
        $id = $this->store()->store($this->collectionId, $this->patientId, $this->upload('l.pdf', self::PDF), $this->uid, new Request('POST', '/'));
        $path = $this->store()->absolutePath($this->store()->find($id));
        $this->store()->delete($id, $this->uid, new Request('POST', '/'));
        $this->assertNull($this->store()->find($id));
        $row = $this->db->one('SELECT * FROM lab_attachments WHERE id = ?', [$id]);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame($this->uid, (int) $row['deleted_by']);
        $this->assertFileExists($path);
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE entity = 'lab_attachments' AND entity_id = ? AND action = 'delete'", [$id]));
    }

    public function test_absolute_path_cannot_escape_uploads(): void
    {
        mkdir($this->storage . '/config', 0777, true);
        file_put_contents($this->storage . '/config/config.php', '<?php return [];');
        $this->expectException(\RuntimeException::class);
        $this->store()->absolutePath(['stored_path' => '../config/config.php']);
    }

    public function test_container_accessor(): void
    {
        $this->assertInstanceOf(AttachmentStore::class, Container::labAttachments());
    }

    public function test_limit_reports_min_of_10mb_and_ini(): void
    {
        $this->assertSame('2', \App\Labs\AttachmentStore::limitMegabytes('2M'));
        $this->assertSame('10', \App\Labs\AttachmentStore::limitMegabytes('64M'));
        $this->assertSame('10', \App\Labs\AttachmentStore::limitMegabytes('1G'));
        $this->assertSame('0,5', \App\Labs\AttachmentStore::limitMegabytes('512K'));
        $this->assertSame('10', \App\Labs\AttachmentStore::limitMegabytes(false));
        $this->assertSame('10', \App\Labs\AttachmentStore::limitMegabytes('0'));
    }
}
