<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Request;
use Tests\DbTestCase;

/**
 * Teste de fumaca: percorre TODAS as rotas GET registradas, para cada perfil, e confere que
 * nenhuma devolve 500 e que o resultado bate com a matriz de acesso (Policy/Gate).
 */
final class SmokeAllRoutesTest extends DbTestCase
{
    // Codigos esperados: 200 = pagina, 403 = negado, 404 = nao encontrado, 302 = redireciona por desenho.
    private const ROLES = ['M', 'N', 'S', 'R', 'P', 'C'];

    /**
     * M = medico admin, N = nutricionista (com liberacao), S = secretaria,
     * R = responsavel (termo aceito), P = paciente adulto (termo aceito, vinculado ao proprio cadastro),
     * C = crianca com conta propria vinculada a crianca de fumaca (guarda 'childarea': so a area de metas,
     *     a propria conta e as rotas publicas; o resto redireciona para /portal/p/{id}/metas).
     * Ordem das colunas: M N S R P C.
     */
    private const EXPECTED = [
        '/instalar'                                => [404, 404, 404, 404, 404, 404], // ja instalado
        '/login'                                   => [302, 302, 302, 302, 302, 302], // logado volta para /
        '/senha/esqueci'                           => [200, 200, 200, 200, 200, 200],
        '/senha/definir/{token}'                   => [404, 404, 404, 404, 404, 404], // token invalido
        '/2fa'                                     => [302, 302, 302, 302, 302, 302], // sem 2FA pendente
        '/2fa/configurar'                          => [200, 200, 200, 200, 200, 302],
        '/'                                        => [200, 200, 200, 302, 302, 302], // familia vai ao /portal
        '/equipe'                                  => [200, 403, 403, 403, 403, 302],
        '/equipe/novo'                             => [200, 403, 403, 403, 403, 302],
        '/equipe/{id}/editar'                      => [200, 403, 403, 403, 403, 302],
        '/admin/backups'                           => [200, 403, 403, 403, 403, 302],
        '/admin/backups/chave-privada'             => [404, 403, 403, 403, 403, 302], // sem chaves no ambiente de teste
        '/admin/backups/{nome}'                    => [404, 403, 403, 403, 403, 302], // arquivo inexistente
        '/pacientes'                               => [200, 200, 200, 302, 302, 302], // familia vai a /portal
        '/pacientes/novo'                          => [200, 403, 200, 403, 403, 302],
        '/pacientes/{id}'                          => [200, 200, 200, 302, 302, 302], // familia vai a /portal/p/{id}
        '/pacientes/{id}/editar'                   => [200, 403, 200, 403, 403, 302],
        '/pacientes/{id}/responsaveis'             => [200, 403, 200, 403, 403, 302],
        '/consentimento'                           => [302, 302, 302, 302, 302, 302], // sem pendencia
        '/pacientes/{id}/acesso-profissionais'     => [200, 403, 403, 403, 403, 302],
        '/maioridade'                              => [200, 403, 403, 403, 403, 302],
        '/pacientes/{id}/crescimento'              => [200, 200, 403, 302, 302, 302], // familia vai a /portal/p/{id}/crescimento
        '/pacientes/{id}/curvas.json'              => [200, 200, 403, 200, 200, 302],
        '/pacientes/{id}/medidas/nova'             => [200, 200, 403, 403, 403, 302],
        '/medidas/{id}/corrigir'                   => [200, 200, 403, 403, 403, 302],
        '/medidas/{id}/versoes'                    => [200, 200, 403, 403, 403, 302],
        '/pacientes/{id}/energia'                  => [200, 200, 403, 403, 403, 302],
        '/pacientes/{id}/plano'                    => [200, 200, 403, 403, 403, 302],
        '/alimentos/busca'                         => [200, 200, 403, 403, 403, 302],
        '/alimentos'                               => [200, 200, 403, 403, 403, 302],
        '/alimentos/novo'                          => [200, 200, 403, 403, 403, 302],
        '/alimentos/{foodId}/editar'               => [200, 200, 403, 403, 403, 302],
        '/modelos-plano'                           => [200, 200, 403, 403, 403, 302],
        '/modelos-plano/novo'                      => [200, 200, 403, 403, 403, 302],
        '/modelos-plano/{templateId}/editar'       => [200, 403, 403, 403, 403, 302], // nutricionista nao e a autora
        '/trechos'                                 => [200, 200, 403, 403, 403, 302],
        '/trechos/novo'                            => [200, 200, 403, 403, 403, 302],
        '/trechos/{snippetId}/editar'              => [200, 403, 403, 403, 403, 302],
        '/trechos.json'                            => [200, 200, 403, 403, 403, 302],
        '/planos/{planId}'                         => [200, 200, 403, 403, 403, 302],
        '/planos/{planId}/imprimir'                => [200, 200, 403, 403, 403, 302],
        '/planos/{planId}/editar'                  => [302, 302, 403, 403, 403, 302], // publicado redireciona para show
        '/pacientes/{id}/prontuario'               => [200, 403, 403, 403, 403, 302],
        '/pacientes/{id}/prontuario/{kind}'        => [200, 403, 403, 403, 403, 302],
        '/prontuario/{entryId}/revisar'            => [200, 403, 403, 403, 403, 302],
        '/prontuario/{entryId}/versoes'            => [200, 403, 403, 403, 403, 302],
        '/pacientes/{id}/exames'                   => [200, 403, 403, 403, 403, 302], // so medico (Clinician antes do Gate)
        '/pacientes/{id}/exames/serie.json'        => [404, 403, 403, 403, 403, 302], // sem ?exame= o exame nao existe: 404 so depois da permissao
        '/pacientes/{id}/exames/nova'              => [200, 403, 403, 403, 403, 302],
        '/exames/{collectionId}'                   => [200, 403, 403, 403, 403, 302],
        '/exames/{collectionId}/editar'            => [200, 403, 403, 403, 403, 302],
        '/catalogo-exames'                         => [200, 403, 403, 403, 403, 302],
        '/catalogo-exames/novo'                    => [200, 403, 403, 403, 403, 302],
        '/catalogo-exames/{testId}/editar'         => [200, 403, 403, 403, 403, 302],
        '/exames/anexos/{attachmentId}'            => [404, 403, 403, 403, 403, 302], // registro sem arquivo no disco: 404 so depois da permissao
        '/pacientes/{id}/tratamento'               => [200, 403, 403, 403, 403, 302], // so medico (Clinician antes do Gate)
        '/pacientes/{id}/tratamento/nova'          => [200, 403, 403, 403, 403, 302],
        '/pacientes/{id}/efeitos/novo'             => [200, 403, 403, 403, 403, 302],
        '/prescricoes/{prescriptionId}'            => [200, 403, 403, 403, 403, 302],
        '/prescricoes/{prescriptionId}/editar'     => [200, 403, 403, 403, 403, 302],
        '/prescricoes/{prescriptionId}/dose'       => [200, 403, 403, 403, 403, 302],
        '/portal'                                  => [302, 302, 302, 302, 302, 302], // equipe volta a /; 1 filho vai direto a ele
        '/portal/p/{id}'                           => [302, 302, 302, 200, 200, 302], // portal e so da familia
        '/portal/p/{id}/crescimento'               => [302, 302, 302, 200, 200, 302],
        '/portal/p/{id}/medidas/nova'              => [302, 302, 302, 200, 200, 302],
        '/portal/p/{id}/alimentacao'               => [302, 302, 302, 200, 200, 302],
        '/portal/p/{id}/alimentacao/imprimir'      => [302, 302, 302, 200, 200, 302],
        '/portal/p/{id}/exames'                    => [302, 302, 302, 200, 200, 302],
        '/portal/p/{id}/metas'                     => [302, 302, 302, 200, 200, 200], // equipe volta a /; familia e crianca veem a area de metas
        '/portal/conta'                            => [302, 302, 302, 200, 200, 200], // C: trocar a propria senha
        '/senha/trocar'                            => [302, 302, 302, 302, 302, 302], // sem senha provisoria volta para /
        '/pacientes/{id}/acesso-crianca'           => [200, 403, 403, 403, 403, 302], // so medico; C vai para a area dele
        '/metas-modelo'                            => [200, 200, 403, 403, 403, 302], // medico e nutricionista; C vai para a area dele
        '/metas-modelo/novo'                       => [200, 200, 403, 403, 403, 302],
        '/metas-modelo/{goalTemplateId}/editar'    => [200, 200, 403, 403, 403, 302], // modelo de alimentacao: nutricionista edita
        '/pacientes/{id}/metas'                    => [200, 200, 403, 403, 403, 302],
        '/pacientes/{id}/metas/nova'               => [200, 200, 403, 403, 403, 302],
        '/metas/{goalId}/editar'                   => [200, 200, 403, 403, 403, 302], // meta de alimentacao do paciente liberado
        '/cartoes'                                 => [200, 403, 403, 403, 403, 302], // so a medica; C vai para a area dele
        '/cartoes/novo'                            => [200, 403, 403, 403, 403, 302],
        '/cartoes/{cardId}/editar'                 => [200, 403, 403, 403, 403, 302],
        '/portal/p/{id}/cartoes/{cardId}'          => [302, 302, 302, 200, 404, 200], // cartao 7-10 publicado; adulto (P) sem faixa
    ];

    private array $fx = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function buildFixtures(): void
    {
        $admin = $this->makeUser('medico', ['is_admin' => 1, 'totp_enabled' => 1]);
        $nutri = $this->makeUser('nutricionista', ['totp_enabled' => 1]);
        $sec = $this->makeUser('secretaria', ['totp_enabled' => 1]);
        $resp = $this->makeUser('responsavel');
        $pac = $this->makeUser('paciente');
        $kid = $this->makeUser('crianca', ['email' => null, 'username' => 'fumaca.' . bin2hex(random_bytes(3))]);

        $year = (int) date('Y');
        $child = $this->makePatient([
            'full_name' => 'Crianca Fumaca', 'sex' => 'F', 'birth_date' => ($year - 8) . '-03-10',
            'responsible_professional_id' => $admin['id'], 'created_by' => $admin['id'],
            'child_user_id' => $kid['id'],
        ]);
        $adult = $this->makePatient([
            'full_name' => 'Adulto Fumaca', 'sex' => 'M', 'birth_date' => ($year - 40) . '-06-15',
            'user_id' => $pac['id'],
            'responsible_professional_id' => $admin['id'], 'created_by' => $admin['id'],
        ]);
        $this->db->insert('guardian_links', [
            'patient_id' => $child['id'], 'user_id' => $resp['id'], 'relationship' => 'mãe', 'created_by' => $admin['id'],
        ]);
        Container::accessGrants()->grant((int) $child['id'], (int) $nutri['id'], (int) $admin['id']);

        $today = date('Y-m-d');
        $mChild = Container::measurements()->create((int) $child['id'], [
            'measured_on' => $today, 'weight_kg' => 30.5, 'height_cm' => 128.0, 'position' => 'em_pe',
        ], 'equipe', (int) $admin['id']);
        $mAdult = Container::measurements()->create((int) $adult['id'], [
            'measured_on' => $today, 'weight_kg' => 82.0, 'height_cm' => 178.0,
        ], 'equipe', (int) $admin['id']);
        $eChild = Container::clinicalEntries()->add((int) $child['id'], 'evolucao', ['anamnese' => 'Teste'], null, $today, (int) $admin['id']);
        $eAdult = Container::clinicalEntries()->add((int) $adult['id'], 'evolucao', ['anamnese' => 'Teste'], null, $today, (int) $admin['id']);

        // Termo LGPD ativo e aceito por responsavel e paciente.
        if ($this->db->one("SELECT id FROM consent_terms WHERE version = '1.0'") === null) {
            $this->db->insert('consent_terms', ['version' => '1.0', 'body_html' => '<p>Termo</p>', 'active' => 1]);
        }
        $this->db->run('UPDATE consent_terms SET active = 1 WHERE version = ?', ['1.0']);
        foreach ([$resp, $pac] as $u) {
            foreach (Container::consents()->pendingFor($u) as $p) {
                Container::consents()->accept((int) $u['id'], $p['patient_id'], $p['term_version'], new Request('POST', '/consentimento'));
            }
        }

        $planId = Container::mealPlans()->createDraft((int) $child['id'], 'texto', [
            'title' => 'Plano fumaca', 'body_text' => "CAFE\n• pao", 'guidance_text' => null, 'structure' => null,
        ], (int) $admin['id']);
        Container::mealPlans()->publish($planId, (int) $admin['id']);

        $collectionId = Container::labs()->create((int) $child['id'], ['collected_on' => $today], [
            (int) Container::labCatalog()->findByCode('glicemia')['id'] => '98',
        ], (int) $admin['id'], new Request('POST', '/'));
        $attachmentId = $this->db->insert('lab_attachments', [
            'collection_id' => $collectionId, 'stored_path' => 'exames/fumaca/inexistente.pdf', 'original_name' => 'laudo.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'uploaded_by' => $admin['id'],
        ]);

        $prescriptionId = Container::prescriptions()->create((int) $child['id'], [
            'drug_name' => 'Medicamento fumaca', 'route' => 'oral', 'start_date' => $today,
            'dose' => '5 mg', 'frequency' => '1 vez ao dia',
        ], $admin, $today, new Request('POST', '/'));

        $this->fx = [
            'users' => ['M' => $admin, 'N' => $nutri, 'S' => $sec, 'R' => $resp, 'P' => $pac, 'C' => $kid],
            'patient' => ['M' => $child, 'N' => $child, 'S' => $child, 'R' => $child, 'P' => $adult, 'C' => $child],
            'measure' => ['M' => $mChild, 'N' => $mChild, 'S' => $mChild, 'R' => $mChild, 'P' => $mAdult, 'C' => $mChild],
            'entry' => ['M' => $eChild, 'N' => $eChild, 'S' => $eChild, 'R' => $eChild, 'P' => $eAdult, 'C' => $eChild],
            'plan' => $planId,
            'collection' => $collectionId,
            'attachment' => $attachmentId,
            'prescription' => $prescriptionId,
            'template' => Container::mealPlanTemplates()->fromPlan($planId, 'Modelo fumaca', (int) $admin['id']),
            'snippet' => Container::textSnippets()->create('Trecho fumaca', 'Beber agua', (int) $admin['id']),
            'goalTemplate' => Container::goals()->saveTemplate(null, [
                'family_text' => 'Meta pronta fumaca', 'emoji' => '💧', 'category' => 'alimentacao', 'times_per_week' => 7, 'active' => 1,
            ], (int) $admin['id'], new Request('POST', '/')),
            'goal' => Container::goals()->create((int) $child['id'], [
                'family_text' => 'Meta fumaca', 'emoji' => '💧', 'category' => 'alimentacao', 'times_per_week' => 7, 'start_date' => $today,
            ], (int) $admin['id'], new Request('POST', '/')),
            'card' => $this->publishedCard((int) $admin['id']),
            'secretaria_id' => (int) $sec['id'],
        ];
    }

    /** Cartao educativo publicado da faixa 7-10 (crianca de fumaca tem 8 anos). */
    private function publishedCard(int $uid): int
    {
        $id = Container::eduCards()->save(null, [
            'title' => 'Cartao fumaca', 'body' => 'Texto do cartao fumaca.', 'emoji' => '📘', 'bands' => ['7-10'],
        ], $uid, new Request('POST', '/'));
        Container::eduCards()->publish($id, $uid, new Request('POST', '/'));
        return $id;
    }

    /** Substitui os parametros do padrao por valores reais conforme o perfil. */
    private function concretePath(string $pattern, string $role): string
    {
        $pid = (int) $this->fx['patient'][$role]['id'];
        $path = preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', function (array $m) use ($pattern, $role, $pid): string {
            return match ($m[1]) {
                'token' => 'token-inexistente',
                'nome' => 'backup-20200101-000000.rbk',
                'kind' => 'evolucao',
                'planId' => (string) $this->fx['plan'],
                'foodId' => '53',
                'templateId' => (string) $this->fx['template'],
                'snippetId' => (string) $this->fx['snippet'],
                'entryId' => (string) $this->fx['entry'][$role],
                'goalTemplateId' => (string) $this->fx['goalTemplate'],
                'goalId' => (string) $this->fx['goal'],
                'cardId' => (string) $this->fx['card'],
                'testId' => (string) Container::labCatalog()->findByCode('ldl')['id'],
                'collectionId' => (string) $this->fx['collection'],
                'prescriptionId' => (string) $this->fx['prescription'],
                'attachmentId' => (string) $this->fx['attachment'],
                'id' => match (true) {
                    str_starts_with($pattern, '/equipe/') => (string) $this->fx['secretaria_id'],
                    str_starts_with($pattern, '/medidas/') => (string) $this->fx['measure'][$role],
                    default => (string) $pid,
                },
                default => throw new \LogicException('Parametro de rota sem fixture: ' . $m[1]),
            };
        }, $pattern);
        return (string) $path;
    }

    private function getRoutes(): array
    {
        $out = [];
        foreach (App::create()->router()->routes() as $r) {
            if ($r['method'] === 'GET') {
                $out[] = preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $r['pattern']);
            }
        }
        return $out;
    }

    public function testEveryGetRouteHasAnExplicitExpectation(): void
    {
        $routes = $this->getRoutes();
        $this->assertGreaterThan(30, count($routes));
        $this->assertSame([], array_values(array_diff($routes, array_keys(self::EXPECTED))), 'Rota GET sem expectativa no teste de fumaca');
        $this->assertSame([], array_values(array_diff(array_keys(self::EXPECTED), $routes)), 'Expectativa para rota inexistente');
    }

    public function testAllGetRoutesPerRole(): void
    {
        $dump = getenv('SMOKE_DUMP') === '1';
        $bad = [];
        foreach (self::ROLES as $i => $role) {
            Container::reset();
            Container::set('db', $this->db);
            \App\Core\Session::reset();
            Container::auth()->completeLogin((int) $this->fx['users'][$role]['id'], new Request('POST', '/login'));
            foreach ($this->getRoutes() as $pattern) {
                $path = $this->concretePath($pattern, $role);
                $query = str_ends_with($pattern, 'curvas.json') ? ['indicador' => 'wfa', 'tipo' => 'z'] : [];
                $res = App::create()->handle(new Request('GET', $path, $query));
                $this->assertNotSame(500, $res->status, "$role GET $path devolveu 500");
                $want = self::EXPECTED[$pattern][$i] ?? null;
                if ($dump) {
                    fwrite(STDERR, sprintf("%s %-45s %d\n", $role, $pattern, $res->status));
                }
                if ($res->status !== $want) {
                    $bad[] = "$role GET $path: esperado " . var_export($want, true) . ', obtido ' . $res->status;
                }
                // Crianca: todo redirecionamento da guarda leva a area de metas dela (nunca a outra tela).
                $childHome = '/portal/p/' . (int) $this->fx['patient']['C']['id'] . '/metas';
                if ($role === 'C' && $res->status === 302 && !in_array($pattern, ['/login', '/2fa', '/senha/trocar'], true)
                    && ($res->headers['Location'] ?? '') !== $childHome) {
                    $bad[] = "C GET $path: redirecionou para " . ($res->headers['Location'] ?? '?') . ", esperado $childHome";
                }
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }
}
