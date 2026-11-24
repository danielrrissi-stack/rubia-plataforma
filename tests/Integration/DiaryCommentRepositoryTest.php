<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Diary\InvalidDiaryException;
use Tests\DbTestCase;

final class DiaryCommentRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-18';

    private array $medico;
    private array $nutri;
    private array $resp;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
        $this->nutri = $this->makeUser('nutricionista');
        $this->resp = $this->makeUser('responsavel');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id'], 'birth_date' => '2015-03-10']);
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    /** Registro de $type feito pela responsavel; devolve [requestId, entryId]. */
    private function entry(string $type = 'agua', int $secret = 0): array
    {
        $req = $this->db->insert('diary_requests', [
            'patient_id' => $this->patient['id'], 'type' => $type, 'weekdays' => 127,
            'start_date' => '2026-11-02', 'created_by' => $this->medico['id'],
        ]);
        $data = $type === 'sono' ? ['slept_at' => '22:00', 'woke_at' => '06:00'] : ['glasses' => 5];
        $id = Container::diaryEntries()->record($req, '2026-11-17', $data, null, $this->resp, self::TODAY, $this->r());
        if ($secret === 1) {
            $this->db->run('UPDATE diary_requests SET secret = 1 WHERE id = ?', [$req]);
        }
        return [$req, $id];
    }

    private function rejects(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Esperava InvalidDiaryException');
        } catch (InvalidDiaryException $e) {
            $this->assertNotSame([], $e->errors());
        }
    }

    public function test_staff_comments_and_family_reads(): void
    {
        [, $entry] = $this->entry();
        $c = Container::diaryComments();
        $a = $c->add($entry, '  Muito bem!  ', $this->medico, $this->r());
        $b = $c->add($entry, 'Continue assim', $this->nutri, $this->r());
        $list = $c->forEntries([$entry])[$entry];
        $this->assertSame([$a, $b], array_map(fn ($x) => (int) $x['id'], $list));
        $this->assertSame('Muito bem!', $list[0]['body']);
        $this->assertSame($this->medico['name'], $list[0]['author_name']);
        $this->assertSame([], $c->forEntries([]));
        $this->rejects(fn () => $c->add($entry, 'oi', $this->resp, $this->r()));
        $this->rejects(fn () => $c->add($entry, '   ', $this->medico, $this->r()));
        $this->rejects(fn () => $c->add($entry, str_repeat('a', 501), $this->medico, $this->r()));
        $this->assertSame(2, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action='create' AND entity='diary_comments'")['n']);
    }

    public function test_nutritionist_cannot_comment_on_sleep_or_secret(): void
    {
        [, $sleep] = $this->entry('sono');
        $this->rejects(fn () => Container::diaryComments()->add($sleep, 'oi', $this->nutri, $this->r()));
        $this->assertNotSame(0, Container::diaryComments()->add($sleep, 'oi', $this->medico, $this->r()));

        [, $secret] = $this->entry('agua', 1);
        $this->rejects(fn () => Container::diaryComments()->add($secret, 'oi', $this->nutri, $this->r()));
        $this->assertNotSame(0, Container::diaryComments()->add($secret, 'oi', $this->medico, $this->r()));
    }

    public function test_only_author_edits_or_deletes(): void
    {
        [, $entry] = $this->entry();
        $c = Container::diaryComments();
        $id = $c->add($entry, 'um', $this->nutri, $this->r());
        $this->rejects(fn () => $c->update($id, 'dois', $this->medico, $this->r()));
        $this->rejects(fn () => $c->delete($id, $this->medico, $this->r()));
        $c->update($id, ' dois ', $this->nutri, $this->r());
        $this->assertSame('dois', $c->forEntries([$entry])[$entry][0]['body']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action='update' AND entity='diary_comments'")['n']);
        $c->delete($id, $this->nutri, $this->r());
        $this->assertSame([], $c->forEntries([$entry]));
        $this->assertNotNull($this->db->one('SELECT deleted_at FROM diary_comments WHERE id = ? AND deleted_at IS NOT NULL', [$id]));
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE action='delete' AND entity='diary_comments'")['n']);
    }
}
