<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** asset(): URL com ?v=<hash do arquivo>, para o navegador pegar o CSS/JS novo logo depois de uma instalacao. */
final class AssetUrlTest extends TestCase
{
    public function test_asset_url_has_content_version(): void
    {
        $hash = substr(md5_file(APP_ROOT . '/public/assets/css/app.css'), 0, 8);
        $this->assertSame('/assets/css/app.css?v=' . $hash, asset('/assets/css/app.css'));
    }

    public function test_missing_file_has_no_version(): void
    {
        $this->assertSame('/assets/nao-existe.css', asset('/assets/nao-existe.css'));
    }

    public function test_layouts_use_versioned_css_and_dictation(): void
    {
        foreach (['layout.php', 'layout_portal.php'] as $l) {
            $src = (string) file_get_contents(APP_ROOT . '/app/Layout/views/' . $l);
            $this->assertStringContainsString("asset('/assets/css/app.css')", $src, $l);
            $this->assertStringContainsString("asset('/assets/js/dictation.js')", $src, $l);
        }
    }
}
