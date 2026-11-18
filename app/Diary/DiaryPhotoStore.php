<?php
declare(strict_types=1);

namespace App\Diary;

use App\Core\Response;
use App\Labs\AttachmentStore;

/** Fotos de refeicao: fora da pasta publica, nome aleatorio, tipo pelo conteudo, reduzidas quando ha GD. */
final class DiaryPhotoStore
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_SIDE = 1280;
    private const MAX_PIXELS = 25_000_000;
    private const BYTES_PER_PIXEL = 10;

    private bool $useGd;

    /**
     * @param bool $allowNonUploaded aceita arquivos que nao vieram de upload HTTP (so para testes)
     * @param ?bool $useGd null = usa a GD quando carregada; false forca guardar o original (testes)
     */
    public function __construct(private string $uploadsDir, private bool $allowNonUploaded = false, ?bool $useGd = null)
    {
        $this->uploadsDir = rtrim($uploadsDir, '/' . chr(92));
        $this->useGd = ($useGd ?? true) && extension_loaded('gd');
    }

    /** A foto cabe no orcamento de memoria para ser decodificada pela GD? (puro, sem GD) */
    public static function shouldResize(int $w, int $h, int $memoryLimitBytes): bool
    {
        if ($w < 1 || $h < 1) {
            return false;
        }
        $pixels = $w * $h;
        if ($pixels > self::MAX_PIXELS) {
            return false;
        }
        return $memoryLimitBytes < 0 || $pixels * self::BYTES_PER_PIXEL <= $memoryLimitBytes;
    }

    /**
     * @param array $file formato de $_FILES['x']
     * @return array{path:string, mime:string, size:int}
     */
    public function store(array $file): array
    {
        $limit = AttachmentStore::limitMegabytes(ini_get('upload_max_filesize'));
        $tooBig = 'A foto é maior que o permitido (máx. ' . $limit . ' MB).';
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidDiaryException([$tooBig]);
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new InvalidDiaryException(['Selecione a foto.']);
        }
        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)
            || (!is_uploaded_file($tmp) && !$this->allowNonUploaded)) {
            throw new InvalidDiaryException(['Não foi possível receber a foto. Tente novamente.']);
        }
        $size = (int) filesize($tmp);
        if ($size > AttachmentStore::MAX_BYTES || (int) ($file['size'] ?? 0) > AttachmentStore::MAX_BYTES
            || $size > self::iniBytes((string) ini_get('upload_max_filesize'))) {
            throw new InvalidDiaryException([$tooBig]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            throw new InvalidDiaryException(['Envie uma foto em JPEG, PNG ou WebP.']);
        }

        $ext = self::TYPES[$mime];
        $image = null;
        if ($this->useGd) {
            $image = $this->resized($tmp, $mime);
            if ($image !== null) {
                $ext = 'jpg';
                $mime = 'image/jpeg';
            }
        }
        $rel = 'diario/' . date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $this->uploadsDir . '/' . $rel;
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar a pasta de fotos.');
        }
        if ($image !== null) {
            $ok = imagejpeg($image, $dest, 82);
        } else {
            $ok = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : ($this->allowNonUploaded && rename($tmp, $dest));
        }
        if (!$ok) {
            @unlink($dest);
            throw new \RuntimeException('Não foi possível gravar a foto.');
        }
        @chmod($dest, 0640);
        clearstatcache(true, $dest);
        return ['path' => $rel, 'mime' => $mime, 'size' => (int) filesize($dest)];
    }

    /** Apaga o arquivo recem-gravado quando a gravacao no banco falhou. */
    public function discard(string $path): void
    {
        $file = $this->resolve($path);
        if ($file !== null) {
            @unlink($file);
        }
    }

    /** Serve inline; arquivo ausente = 404. */
    public function response(array $entry): Response
    {
        $file = $this->resolve((string) ($entry['photo_path'] ?? ''));
        if ($file === null) {
            return new Response('Foto não encontrada.', 404);
        }
        return Response::file($file, (string) $entry['photo_mime'])
            ->header('Cache-Control', 'private, no-store');
    }

    /** Caminho real, garantidamente dentro de uploads/diario. */
    private function resolve(string $rel): ?string
    {
        if ($rel === '' || !str_starts_with($rel, 'diario/')) {
            return null;
        }
        $root = realpath($this->uploadsDir . '/diario');
        $path = realpath($this->uploadsDir . '/' . $rel);
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $path;
    }

    /** Imagem reduzida (maior lado <= 1280, sem ampliar) sobre fundo branco; null se a GD nao puder ou nao couber na memoria. */
    private function resized(string $tmp, string $mime): ?\GdImage
    {
        $info = @getimagesize($tmp);
        if ($info === false || !self::shouldResize((int) $info[0], (int) $info[1], self::memoryLimit())) {
            return null;
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
            default => false,
        };
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $angle = self::rotationFor($this->orientation($tmp));
        if ($angle !== 0) {
            $rotated = imagerotate($dst, $angle, 0);
            if ($rotated !== false) {
                imagedestroy($dst);
                $dst = $rotated;
            }
        }
        return $dst;
    }

    private static function memoryLimit(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        return $v === '-1' ? -1 : self::iniBytes($v);
    }

    /**
     * Graus anti-horarios para imagerotate() que deixam a foto em pe, dado o Orientation do EXIF.
     * Valores espelhados (2, 4, 5, 7) usam so a rotacao equivalente; desconhecido = 0.
     */
    public static function rotationFor(int $orientation): int
    {
        return match ($orientation) {
            3, 4 => 180,
            6, 5 => 270,
            8, 7 => 90,
            default => 0,
        };
    }

    /** Orientation do EXIF de um JPEG (1 quando nao ha EXIF ou a extensao falta). */
    private function orientation(string $file): int
    {
        if (!function_exists('exif_read_data') || (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file) !== 'image/jpeg') {
            return 1;
        }
        $exif = @exif_read_data($file);
        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    private static function iniBytes(string $v): int
    {
        $v = strtolower(trim($v));
        if (preg_match('/^(\d+)\s*([kmg]?)b?$/', $v, $m) !== 1 || (int) $m[1] <= 0) {
            return PHP_INT_MAX;
        }
        return (int) $m[1] * ['' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3][$m[2]];
    }
}
