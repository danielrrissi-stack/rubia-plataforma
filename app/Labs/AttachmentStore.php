<?php
declare(strict_types=1);

namespace App\Labs;

use App\Audit\AuditLog;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Laudos (PDF/JPEG/PNG) das coletas: guardados fora da pasta publica, com nome aleatorio,
 * tipo detectado pelo conteudo e exclusao apenas logica.
 */
final class AttachmentStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /** @param bool $allowNonUploaded aceita arquivos que nao vieram de upload HTTP (so para testes) */
    public function __construct(
        private Database $db,
        private AuditLog $audit,
        private string $uploadsDir,
        private bool $allowNonUploaded = false,
    ) {
        $this->uploadsDir = rtrim($uploadsDir, '/\\');
    }

    /** @param array $file um arquivo no formato de $_FILES['x'] */
    public function store(int $collectionId, int $patientId, array $file, int $uid, Request $r): int
    {
        return $this->persist($collectionId, $patientId, [$file], $uid, $r)[0];
    }

    /**
     * @param array $filesField campo multi-arquivo de $_FILES (name[]); entradas vazias sao ignoradas
     * @return list<int>
     */
    public function storeMany(int $collectionId, int $patientId, array $filesField, int $uid, Request $r): array
    {
        $files = array_values(array_filter(
            self::normalize($filesField),
            static fn(array $f): bool => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE,
        ));
        return $files === [] ? [] : $this->persist($collectionId, $patientId, $files, $uid, $r);
    }

    /** Anexo ativo com o patient_id da coleta. */
    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT a.*, c.patient_id FROM lab_attachments a JOIN lab_collections c ON c.id = a.collection_id
              WHERE a.id = ? AND a.deleted_at IS NULL',
            [$id],
        );
    }

    /** Anexos ativos de uma coleta, na ordem de envio. @return list<array> */
    public function forCollection(int $collectionId): array
    {
        return $this->db->all(
            'SELECT id, collection_id, original_name, mime, size_bytes, uploaded_at FROM lab_attachments
              WHERE collection_id = ? AND deleted_at IS NULL ORDER BY id',
            [$collectionId],
        );
    }

    /** Caminho real do arquivo, garantidamente dentro da pasta de uploads. */
    public function absolutePath(array $att): string
    {
        $root = realpath($this->uploadsDir);
        $path = realpath($this->uploadsDir . '/' . (string) ($att['stored_path'] ?? ''));
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Arquivo de laudo indisponível.');
        }
        return $path;
    }

    /** Exclusao logica: o arquivo permanece no disco (e nos backups). */
    public function delete(int $id, int $uid, Request $r): void
    {
        $this->db->transaction(function () use ($id, $uid, $r): void {
            $before = $this->find($id);
            if ($before === null) {
                throw new \RuntimeException('Laudo não encontrado.');
            }
            $this->db->run('UPDATE lab_attachments SET deleted_at = NOW(), deleted_by = ? WHERE id = ?', [$uid, $id]);
            $after = $this->db->one('SELECT * FROM lab_attachments WHERE id = ?', [$id]);
            $this->audit->record($uid, 'delete', 'lab_attachments', $id, (int) $before['patient_id'], $before, $after, $r);
        });
    }

    public function downloadResponse(array $att): Response
    {
        return Response::file($this->absolutePath($att), (string) $att['mime'], (string) $att['original_name'])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Valida todos os arquivos antes de mover qualquer um; se a gravacao no banco falhar,
     * remove os arquivos ja movidos.
     * @param list<array> $files
     * @return list<int>
     */
    private function persist(int $collectionId, int $patientId, array $files, int $uid, Request $r): array
    {
        $owner = $this->db->one('SELECT patient_id FROM lab_collections WHERE id = ?', [$collectionId]);
        if ($owner === null || (int) $owner['patient_id'] !== $patientId) {
            throw new InvalidLabException(['Coleta não encontrada.']);
        }
        $checked = [];
        $errors = [];
        foreach ($files as $f) {
            $result = $this->validate($f);
            if (is_string($result)) {
                $errors[] = $result;
            } else {
                $checked[] = $result;
            }
        }
        if ($errors !== []) {
            throw new InvalidLabException(array_values(array_unique($errors)));
        }

        $moved = [];
        try {
            foreach ($checked as $i => $c) {
                $rel = 'exames/' . date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.' . $c['ext'];
                $dest = $this->uploadsDir . '/' . $rel;
                $dir = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Não foi possível criar a pasta de laudos.');
                }
                $ok = is_uploaded_file($c['tmp'])
                    ? move_uploaded_file($c['tmp'], $dest)
                    : ($this->allowNonUploaded && rename($c['tmp'], $dest));
                if (!$ok) {
                    throw new \RuntimeException('Não foi possível gravar o laudo.');
                }
                @chmod($dest, 0640);
                $moved[] = $dest;
                $checked[$i]['stored_path'] = $rel;
            }
            return $this->db->transaction(function () use ($checked, $collectionId, $patientId, $uid, $r): array {
                $ids = [];
                foreach ($checked as $c) {
                    $row = [
                        'collection_id' => $collectionId,
                        'stored_path' => $c['stored_path'],
                        'original_name' => $c['name'],
                        'mime' => $c['mime'],
                        'size_bytes' => $c['size'],
                        'sha256' => $c['sha256'],
                        'uploaded_by' => $uid,
                    ];
                    $id = $this->db->insert('lab_attachments', $row);
                    $this->audit->record($uid, 'upload', 'lab_attachments', $id, $patientId, null, ['id' => $id] + $row, $r);
                    $ids[] = $id;
                }
                return $ids;
            });
        } catch (\Throwable $e) {
            foreach ($moved as $path) {
                @unlink($path);
            }
            throw $e;
        }
    }

    /** Limite real de envio, em MB inteiros: o menor entre 10 MB e o upload_max_filesize do PHP (ex.: "2M", "512K", "1G"). */
    public static function limitMegabytes(string|false $iniValue): string
    {
        $max = self::MAX_BYTES;
        $v = is_string($iniValue) ? strtolower(trim($iniValue)) : '';
        if ($v !== '' && preg_match('/^(\d+)\s*([kmg]?)b?$/', $v, $m) === 1 && (int) $m[1] > 0) {
            $mult = ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3][$m[2]];
            $max = min($max, (int) $m[1] * $mult);
        }
        $mb = $max / (1024 * 1024);
        return $mb >= 1 ? (string) (int) $mb : rtrim(rtrim(number_format($mb, 1, ',', ''), '0'), ',');
    }

    /** @return string|array{tmp:string,name:string,mime:string,ext:string,size:int,sha256:string} mensagem de erro ou dados do arquivo */
    private function validate(array $f): string|array
    {
        $name = self::cleanName(is_string($f['name'] ?? null) ? $f['name'] : '');
        $error = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        $tooBig = "O arquivo $name é maior que o permitido (máx. 10 MB).";
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return "O arquivo $name é maior que o permitido (máx. " . self::limitMegabytes(ini_get('upload_max_filesize')) . ' MB).';
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            return 'Selecione o arquivo do laudo.';
        }
        $tmp = is_string($f['tmp_name'] ?? null) ? $f['tmp_name'] : '';
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)
            || (!is_uploaded_file($tmp) && !$this->allowNonUploaded)) {
            return "Não foi possível receber o arquivo $name. Tente novamente.";
        }
        $size = (int) filesize($tmp);
        if ($size > self::MAX_BYTES || (int) ($f['size'] ?? 0) > self::MAX_BYTES) {
            return $tooBig;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            return 'Envie o laudo em PDF, JPG ou PNG.';
        }
        return [
            'tmp' => $tmp,
            'name' => $name,
            'mime' => $mime,
            'ext' => self::TYPES[$mime],
            'size' => $size,
            'sha256' => (string) hash_file('sha256', $tmp),
        ];
    }

    /** Nome original so para exibicao/download: basename, sem caracteres de controle, ate 200 caracteres. */
    private static function cleanName(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $parts = preg_split('#[/\\\\]#', $name) ?: [''];
        $name = trim((string) end($parts));
        $name = mb_substr($name, 0, 200);
        return $name === '' || $name === '.' || $name === '..' ? 'laudo' : $name;
    }

    /** Converte o formato multi-arquivo do PHP (name[], tmp_name[]...) em lista de arquivos. */
    private static function normalize(array $field): array
    {
        if (!isset($field['name'])) {
            return array_values(array_filter($field, 'is_array'));
        }
        if (!is_array($field['name'])) {
            return [$field];
        }
        $out = [];
        foreach (array_keys($field['name']) as $k) {
            if (!is_string($field['name'][$k]) || !is_string($field['tmp_name'][$k] ?? '')) {
                continue; // name[a][b] aninhado nao e aceito
            }
            $out[] = [
                'name' => $field['name'][$k] ?? '',
                'type' => $field['type'][$k] ?? '',
                'tmp_name' => $field['tmp_name'][$k] ?? '',
                'error' => $field['error'][$k] ?? UPLOAD_ERR_NO_FILE,
                'size' => $field['size'][$k] ?? 0,
            ];
        }
        return $out;
    }
}
