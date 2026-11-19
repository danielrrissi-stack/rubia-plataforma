<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Forbidden');
}

/**
 * Lista das fotos do manual em JSON (consumida por tools/manual_shots.mjs).
 * Uso: php tools/manual_shots_list.php  (le storage/demo/ids.json gravado por tools/demo/seed.php)
 */

/**
 * @param list<array{file:string,url:string,as:?string,width:string}> $images
 * @param array<string,int|string> $ids
 * @return list<array{file:string,url:string,as:?string,width:string}>
 */
function manualShotList(array $images, array $ids): array
{
    $out = [];
    $seen = [];
    foreach ($images as $img) {
        if (isset($seen[$img['file']])) {
            throw new RuntimeException('Arquivo de foto repetido: ' . $img['file']);
        }
        $seen[$img['file']] = true;
        $url = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($ids, $img): string {
            if (!array_key_exists($m[1], $ids)) {
                throw new RuntimeException('Marcador {' . $m[1] . '} sem valor (arquivo ' . $img['file'] . ')');
            }
            return (string) $ids[$m[1]];
        }, $img['url']);
        $out[] = ['file' => $img['file'], 'url' => $url, 'as' => $img['as'], 'width' => $img['width']];
    }
    return $out;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require __DIR__ . '/../app/bootstrap.php';
    $idsFile = APP_ROOT . '/storage/demo/ids.json';
    if (!is_file($idsFile)) {
        fwrite(STDERR, "Rode antes: APP_ENV=demo php tools/demo/seed.php\n");
        exit(1);
    }
    try {
        $list = manualShotList(\App\Help\Manual::allImages(), (array) json_decode((string) file_get_contents($idsFile), true));
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    echo json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
}
