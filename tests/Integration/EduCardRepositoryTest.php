<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Goals\InvalidGoalException;
use Tests\DbTestCase;

final class EduCardRepositoryTest extends DbTestCase
{
    private const BANNED = ['peso', 'gord', 'magr', 'dieta', 'obes'];

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function data(array $over = []): array
    {
        return $over + ['title' => 'Água é a melhor bebida', 'body' => 'Leve sua garrafinha para todo lado.', 'emoji' => '💧', 'bands' => ['7-10'], 'sort_order' => '5'];
    }

    public function test_seed_has_40_drafts_covering_bands(): void
    {
        $rows = $this->db->all('SELECT * FROM edu_cards WHERE id BETWEEN 1 AND 999 ORDER BY id');
        $this->assertCount(40, $rows);
        $this->assertSame(range(1, 40), array_map(static fn (array $c): int => (int) $c['id'], $rows));
        $perBand = array_fill_keys(['3-6', '7-10', '11-14', '15-19'], 0);
        foreach ($rows as $c) {
            $this->assertSame('rascunho', $c['status'], 'cartão ' . $c['id']);
            $this->assertLessThanOrEqual(60, mb_strlen($c['title']), 'título do cartão ' . $c['id']);
            $this->assertLessThanOrEqual(600, mb_strlen($c['body']), 'texto do cartão ' . $c['id']);
            $this->assertGreaterThanOrEqual(10, mb_strlen($c['body']), 'texto do cartão ' . $c['id']);
            $this->assertNotSame('', trim($c['emoji']));
            $text = mb_strtolower($c['title'] . ' ' . $c['body']);
            foreach (self::BANNED as $w) {
                $this->assertStringNotContainsString($w, $text, 'cartão ' . $c['id'] . ' contém "' . $w . '"');
            }
            foreach (explode(',', $c['bands']) as $b) {
                $perBand[$b]++;
            }
        }
        foreach ($perBand as $band => $n) {
            $this->assertGreaterThanOrEqual(8, $n, 'faixa ' . $band);
        }
    }

    public function test_drafts_never_reach_portal(): void
    {
        foreach ([3, 5, 7, 10, 11, 14, 15, 19] as $age) {
            $this->assertSame([], Container::eduCards()->publishedForAge($age), 'idade ' . $age);
        }
    }

    public function test_published_by_age_band(): void
    {
        $cards = Container::eduCards();
        $uid = (int) $this->makeUser('medico')['id'];
        $kid = $cards->save(null, $this->data(['bands' => ['7-10'], 'sort_order' => '20']), $uid, $this->r());
        $kid2 = $cards->save(null, $this->data(['title' => 'Primeiro da lista', 'bands' => ['7-10'], 'sort_order' => '1']), $uid, $this->r());
        $teen = $cards->save(null, $this->data(['bands' => ['11-14', '15-19']]), $uid, $this->r());
        foreach ([$kid, $kid2, $teen] as $id) {
            $cards->publish($id, $uid, $this->r());
        }
        $ids = static fn (array $l): array => array_map(static fn (array $c): int => (int) $c['id'], $l);

        $this->assertSame([], $cards->publishedForAge(2));
        $this->assertSame([$kid2, $kid], $ids($cards->publishedForAge(7)));
        $this->assertSame([$teen], $ids($cards->publishedForAge(19)));
        $this->assertSame([$teen], $ids($cards->publishedForAge(12)));
        $this->assertSame([], $cards->publishedForAge(20));

        $this->assertNull($cards->bandFor(2));
        $this->assertSame('3-6', $cards->bandFor(3));
        $this->assertSame('3-6', $cards->bandFor(6));
        $this->assertSame('7-10', $cards->bandFor(7));
        $this->assertSame('11-14', $cards->bandFor(14));
        $this->assertSame('15-19', $cards->bandFor(19));
        $this->assertNull($cards->bandFor(20));

        $cards->unpublish($kid, $uid, $this->r());
        $this->assertSame([$kid2], $ids($cards->publishedForAge(8)));
    }

    public function test_save_validates(): void
    {
        $cards = Container::eduCards();
        $uid = (int) $this->makeUser('medico')['id'];
        foreach ([
            ['title' => 'ab'],
            ['title' => str_repeat('a', 61)],
            ['body' => 'curto'],
            ['body' => str_repeat('a', 601)],
            ['emoji' => ''],
            ['bands' => []],
            ['bands' => ['0-2']],
        ] as $bad) {
            try {
                $cards->save(null, $this->data($bad), $uid, $this->r());
                $this->fail('Deveria recusar ' . json_encode($bad));
            } catch (InvalidGoalException $e) {
                $this->assertNotEmpty($e->errors);
            }
        }
        $id = $cards->save(null, $this->data(['bands' => ['15-19', '3-6']]), $uid, $this->r());
        $row = $cards->find($id);
        $this->assertSame('3-6,15-19', $row['bands']);
        $this->assertSame('rascunho', $row['status']);
        $this->assertGreaterThanOrEqual(1000, $id);
    }

    public function test_publish_audited(): void
    {
        $cards = Container::eduCards();
        $uid = (int) $this->makeUser('medico')['id'];
        $cards->save(1, $this->data(['title' => 'Título revisado']), $uid, $this->r());
        $cards->publish(1, $uid, $this->r());
        $row = $cards->find(1);
        $this->assertSame('publicado', $row['status']);
        $this->assertSame($uid, (int) $row['approved_by']);
        $this->assertNotNull($row['approved_at']);
        $this->assertSame($uid, (int) $row['updated_by']);
        $cards->unpublish(1, $uid, $this->r());
        $this->assertSame('rascunho', $cards->find(1)['status']);
        foreach (['update', 'publish', 'unpublish'] as $a) {
            $this->assertSame(1, (int) $this->db->one(
                "SELECT COUNT(*) n FROM audit_log WHERE action = ? AND entity = 'edu_cards' AND entity_id = 1 AND user_id = ?",
                [$a, $uid]
            )['n'], $a);
        }
    }
}
