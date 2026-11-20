<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scripts de tools/ e cron/ so podem rodar pela linha de comando: se o pacote for extraido por engano
 * na raiz publica do site, abri-los pelo navegador tem de dar 403 antes de qualquer require ou acesso ao banco.
 */
final class CliOnlyScriptsTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function scripts(): iterable
    {
        $root = dirname(__DIR__, 2);
        foreach (array_merge(glob($root . '/tools/*.php') ?: [], glob($root . '/cron/*.php') ?: []) as $f) {
            yield basename(dirname($f)) . '/' . basename($f) => [$f];
        }
    }

    #[DataProvider('scripts')]
    public function testGuardBeforeAnyRequire(string $file): void
    {
        // So o codigo conta: comentarios e espacos saem (uma trava dentro de comentario nao protege nada).
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true)) {
                    continue;
                }
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        $guard = strpos($code, "if(PHP_SAPI!=='cli'){http_response_code(403);exit(");
        $this->assertNotFalse($guard, 'Falta a trava de linha de comando (com 403) em ' . $file);
        $req = strpos($code, 'require');
        $this->assertTrue($req === false || $guard < $req, 'A trava precisa vir antes do primeiro require em ' . $file);
        // Antes da trava, so o declare(strict_types=1).
        $this->assertSame('declare(strict_types=1);', substr($code, 0, $guard), 'A trava precisa ser a primeira instrucao em ' . $file);
    }
}
