<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Certificates\InvalidCertificateException;
use App\Core\Container;
use Tests\DbTestCase;

final class CertificateRepositoryTest extends DbTestCase
{
    private array $doc;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = $this->makeUser('medico', ['specialty' => 'Pediatra', 'rqe' => '107605']);
        $this->patient = $this->makePatient(['created_by' => $this->doc['id'], 'responsible_professional_id' => $this->doc['id']]);
    }

    private function make(string $issued = '2026-11-19', ?string $cid = null): int
    {
        return Container::certificates()->create((int) $this->patient['id'], (int) $this->doc['id'], 'comparecimento',
            $issued, ['data' => $issued], '<p>corpo</p>', $cid);
    }

    public function test_create_and_find(): void
    {
        $id = $this->make('2026-11-19', 'E66.9');
        $c = Container::certificates()->find($id);
        $this->assertSame('comparecimento', $c['type']);
        $this->assertSame('2026-11-19', $c['issued_on']);
        $this->assertSame('E66.9', $c['cid']);
        $this->assertSame('<p>corpo</p>', $c['body_html']);
        $this->assertSame(['data' => '2026-11-19'], json_decode($c['payload'], true));
        $this->assertNull($c['voided_at']);
        $this->assertNull(Container::certificates()->find($id + 1000));
    }

    public function test_for_patient_newest_first_and_limit(): void
    {
        $a = $this->make('2026-11-01');
        $b = $this->make('2026-11-10');
        $c = $this->make('2026-11-10');
        $all = Container::certificates()->forPatient((int) $this->patient['id']);
        $this->assertSame([$c, $b, $a], array_map('intval', array_column($all, 'id')));
        $this->assertCount(2, Container::certificates()->forPatient((int) $this->patient['id'], 2));
    }

    public function test_void_marks_and_refuses_twice(): void
    {
        $id = $this->make();
        Container::certificates()->void($id, (int) $this->doc['id'], 'Data errada');
        $c = Container::certificates()->find($id);
        $this->assertNotNull($c['voided_at']);
        $this->assertSame('Data errada', $c['void_reason']);
        $this->assertSame((int) $this->doc['id'], (int) $c['voided_by']);
        $this->expectException(InvalidCertificateException::class);
        Container::certificates()->void($id, (int) $this->doc['id'], 'De novo');
    }

    public function test_void_requires_reason(): void
    {
        $id = $this->make();
        foreach (['   ', str_repeat('x', 201)] as $bad) {
            try {
                Container::certificates()->void($id, (int) $this->doc['id'], $bad);
                $this->fail('deveria recusar');
            } catch (InvalidCertificateException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull(Container::certificates()->find($id)['voided_at']);
    }
}
