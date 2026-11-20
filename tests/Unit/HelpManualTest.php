<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Help\Manual;
use PHPUnit\Framework\TestCase;

final class HelpManualTest extends TestCase
{
    public function test_slugs_and_titles(): void
    {
        $this->assertSame('medica', Manual::slug('medico'));
        $this->assertSame('medico', Manual::roleFromSlug('medica'));
        $this->assertNull(Manual::roleFromSlug('xx'));
        $this->assertSame('Manual da criança', Manual::title('crianca'));
        $this->assertSame('Manual da secretária', Manual::title('secretaria'));
    }

    public function test_every_role_has_valid_sections(): void
    {
        foreach (Manual::ROLES as $role) {
            $sections = Manual::sections($role, true);
            $this->assertNotEmpty($sections, $role);
            $ids = [];
            foreach ($sections as $s) {
                $this->assertNotContains($s['id'], $ids, $role . ': id repetido ' . $s['id']);
                $ids[] = $s['id'];
                $this->assertNotSame('', $s['title'], $role);
                $this->assertNotEmpty($s['steps'], $role . '/' . $s['id']);
                foreach ($s['images'] as $img) {
                    $this->assertStringStartsWith(Manual::slug($role) . '/', $img['file'], $role);
                    $this->assertStringEndsWith('.jpg', $img['file']);
                    $this->assertContains($img['width'], ['desktop', 'mobile']);
                    $this->assertStringEndsWith(Manual::CAPTION_SUFFIX, $img['caption'], $img['file']);
                    $this->assertStringStartsWith('/', $img['url'], $img['file']);
                }
            }
        }
    }

    public function test_doctor_manual_has_certificates_after_prescription(): void
    {
        $ids = array_column(Manual::sections('medico', false), 'id');
        $this->assertContains('atestados', $ids);
        $this->assertSame(array_search('receita', $ids, true) + 1, array_search('atestados', $ids, true));
    }

    public function test_nutritionist_manual_explains_templates_in_detail(): void
    {
        $ids = array_column(Manual::sections('nutricionista', false), 'id');
        $want = ['modelos', 'modelo-texto', 'modelo-estruturado', 'trechos', 'usar-modelo', 'dietas-prontas', 'alterar-modelos'];
        $start = array_search('modelos', $ids, true);
        $this->assertNotFalse($start);
        $this->assertSame($want, array_slice($ids, (int) $start, count($want)));
        $text = mb_strtolower($this->text('nutricionista'));
        foreach (['linha terminada em dois-pontos', 'opção 1', 'a cada 30 segundos', 'inserir no cursor', 'criar rascunho', 'salvar como modelo'] as $w) {
            $this->assertStringContainsString($w, $text, $w);
        }
    }

    public function test_first_section_is_first_access(): void
    {
        foreach (Manual::ROLES as $role) {
            $this->assertSame('entrar', Manual::sections($role, false)[0]['id'], $role);
        }
    }

    public function test_admin_sections_filtered(): void
    {
        foreach (Manual::sections('medico', false) as $s) {
            $this->assertFalse($s['admin'], $s['id']);
        }
        foreach (array_diff(Manual::ROLES, ['medico']) as $role) {
            foreach (Manual::sections($role, true) as $s) {
                $this->assertFalse($s['admin'], $role . '/' . $s['id']);
            }
        }
    }

    public function test_nutritionist_manual_never_mentions_forbidden_modules(): void
    {
        $text = mb_strtolower($this->text('nutricionista'));
        foreach (['medicament', 'exame', 'evolução', 'receita'] as $w) {
            $this->assertStringNotContainsString($w, $text, $w);
        }
    }

    public function test_family_and_child_images_are_mobile(): void
    {
        foreach (Manual::ROLES as $role) {
            $want = in_array($role, ['responsavel', 'paciente', 'crianca'], true) ? 'mobile' : 'desktop';
            foreach (Manual::sections($role, true) as $s) {
                foreach ($s['images'] as $img) {
                    $this->assertSame($want, $img['width'], $img['file']);
                }
            }
        }
    }

    public function test_all_images_carry_role(): void
    {
        $all = Manual::allImages();
        $this->assertNotEmpty($all);
        foreach ($all as $img) {
            $this->assertContains($img['role'], Manual::ROLES);
            $this->assertStringStartsWith(Manual::slug($img['role']) . '/', $img['file']);
        }
    }

    private function text(string $role): string
    {
        $parts = [];
        foreach (Manual::sections($role, true) as $s) {
            $parts[] = $s['title'];
            $parts[] = (string) $s['intro'];
            $parts[] = (string) $s['tip'];
            array_push($parts, ...$s['steps']);
            foreach ($s['images'] as $img) {
                $parts[] = $img['caption'];
            }
        }
        return implode("\n", $parts);
    }
}
