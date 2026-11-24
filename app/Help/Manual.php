<?php
declare(strict_types=1);

namespace App\Help;

/**
 * Manual de utilizacao: um por perfil. O conteudo fica em content/<papel>.php como dados (lista de secoes);
 * a mesma lista alimenta a pagina /ajuda, o roteiro das fotos e o PDF.
 */
final class Manual
{
    public const ROLES = ['medico', 'nutricionista', 'secretaria', 'responsavel', 'paciente', 'crianca'];

    public const NOTICE = 'Todos os nomes, pacientes e números deste manual são fictícios, criados só para mostrar como o sistema funciona.';

    public const CAPTION_SUFFIX = 'Exemplo com dados fictícios.';

    private const SLUGS = [
        'medico' => 'medica', 'nutricionista' => 'nutricionista', 'secretaria' => 'secretaria',
        'responsavel' => 'responsavel', 'paciente' => 'paciente', 'crianca' => 'crianca',
    ];

    private const TITLES = [
        'medico' => 'Manual da médica', 'nutricionista' => 'Manual da nutricionista',
        'secretaria' => 'Manual da secretária', 'responsavel' => 'Manual do responsável',
        'paciente' => 'Manual do paciente', 'crianca' => 'Manual da criança',
    ];

    /** @var array<string, list<array>> */
    private static array $cache = [];

    public static function slug(string $role): string
    {
        return self::SLUGS[$role] ?? throw new \InvalidArgumentException('Perfil desconhecido: ' . $role);
    }

    public static function roleFromSlug(string $slug): ?string
    {
        $role = array_search($slug, self::SLUGS, true);
        return $role === false ? null : $role;
    }

    public static function title(string $role): string
    {
        return self::TITLES[$role] ?? throw new \InvalidArgumentException('Perfil desconhecido: ' . $role);
    }

    /** Caminho publico do PDF pronto do perfil. */
    public static function pdfPath(string $role): string
    {
        return '/assets/manual/pdf/manual-' . self::slug($role) . '.pdf';
    }

    /**
     * Secoes do manual do perfil, normalizadas. $withAdmin inclui as secoes so da administradora.
     * @return list<array{id:string,title:string,intro:?string,steps:list<string>,tip:?string,admin:bool,images:list<array>}>
     */
    public static function sections(string $role, bool $withAdmin): array
    {
        self::slug($role);
        if (!isset(self::$cache[$role])) {
            $raw = require __DIR__ . '/content/' . $role . '.php';
            self::$cache[$role] = array_map([self::class, 'normalize'], $raw);
        }
        return array_values(array_filter(self::$cache[$role], static fn (array $s): bool => $withAdmin || !$s['admin']));
    }

    /** Todas as imagens dos seis manuais, com o perfil. @return list<array> */
    public static function allImages(): array
    {
        $out = [];
        foreach (self::ROLES as $role) {
            foreach (self::sections($role, true) as $s) {
                foreach ($s['images'] as $img) {
                    $out[] = $img + ['role' => $role, 'section' => $s['id']];
                }
            }
        }
        return $out;
    }

    /**
     * Uma imagem de secao. $file relativo a public/assets/manual/ ("<slug>/<nome>.jpg"); $url da tela no servidor
     * de demonstracao (aceita marcadores {chave}); $as = login usado na foto (perfil, 'novo' ou null = deslogado).
     */
    public static function img(string $file, string $url, ?string $as, string $width, string $caption): array
    {
        return ['file' => $file, 'url' => $url, 'as' => $as, 'width' => $width,
            'caption' => rtrim($caption) . ' ' . self::CAPTION_SUFFIX];
    }

    private static function normalize(array $s): array
    {
        return [
            'id' => (string) $s['id'],
            'title' => (string) $s['title'],
            'intro' => isset($s['intro']) ? (string) $s['intro'] : null,
            'steps' => array_values(array_map('strval', $s['steps'] ?? [])),
            'tip' => isset($s['tip']) ? (string) $s['tip'] : null,
            'admin' => (bool) ($s['admin'] ?? false),
            'images' => array_values($s['images'] ?? []),
        ];
    }
}
