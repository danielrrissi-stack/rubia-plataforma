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
    private const ROLES = ['M', 'N', 'S', 'R', 'P'];

    /**
     * M = medico admin, N = nutricionista (com liberacao), S = secretaria,
     * R = responsavel (termo aceito), P = paciente adulto (termo aceito, vinculado ao proprio cadastro).
     * Ordem das colunas: M N S R P.
     */
    private const EXPECTED = [
        '/instalar'                                => [404, 404, 404, 404, 404], // ja instalado
        '/login'                                   => [302, 302, 302, 302, 302], // logado volta para /
        '/senha/esqueci'                           => [200, 200, 200, 200, 200],
        '/senha/definir/{token}'                   => [404, 404, 404, 404, 404], // token invalido
        '/2fa'                                     => [302, 302, 302, 302, 302], // sem 2FA pendente
        '/2fa/configurar'                          => [200, 200, 200, 200, 200],
        '/'                                        => [200, 200, 200, 302, 302], // familia vai ao /portal
        '/equipe'                                  => [200, 403, 403, 403, 403],
        '/equipe/novo'                             => [200, 403, 403, 403, 403],
        '/equipe/{id}/editar'                      => [200, 403, 403, 403, 403],
        '/admin/backups'                           => [200, 403, 403, 403, 403],
        '/admin/backups/chave-privada'             => [404, 403, 403, 403, 403], // sem chaves no ambiente de teste
        '/admin/backups/{nome}'                    => [404, 403, 403, 403, 403], // arquivo inexistente
        '/pacientes'                               => [200, 200, 200, 302, 302], // familia vai a /portal
        '/pacientes/novo'                          => [200, 403, 200, 403, 403],
        '/pacientes/{id}'                          => [200, 200, 200, 302, 302], // familia vai a /portal/p/{id}
        '/pacientes/{id}/editar'                   => [200, 403, 200, 403, 403],
        '/pacientes/{id}/responsaveis'             => [200, 403, 200, 403, 403],
        '/consentimento'                           => [302, 302, 302, 302, 302], // sem pendencia
        '/pacientes/{id}/acesso-profissionais'     => [200, 403, 403, 403, 403],
        '/maioridade'                              => [200, 403, 403, 403, 403],
        '/pacientes/{id}/crescimento'              => [200, 200, 403, 302, 302], // familia vai a /portal/p/{id}/crescimento
        '/pacientes/{id}/curvas.json'              => [200, 200, 403, 200, 200],
        '/pacientes/{id}/medidas/nova'             => [200, 200, 403, 403, 403],
        '/medidas/{id}/corrigir'                   => [200, 200, 403, 403, 403],
        '/medidas/{id}/versoes'                    => [200, 200, 403, 403, 403],
        '/pacientes/{id}/energia'                  => [200, 200, 403, 403, 403],
        '/pacientes/{id}/prontuario'               => [200, 403, 403, 403, 403],
        '/pacientes/{id}/prontuario/{kind}'        => [200, 403, 403, 403, 403],
        '/prontuario/{entryId}/revisar'            => [200, 403, 403, 403, 403],
        '/prontuario/{entryId}/versoes'            => [200, 403, 403, 403, 403],
        '/portal'                                  => [302, 302, 302, 302, 302], // equipe volta a /; 1 filho vai direto a ele
        '/portal/p/{id}'                           => [302, 302, 302, 200, 200], // portal e so da familia
        '/portal/p/{id}/crescimento'               => [302, 302, 302, 200, 200],
        '/portal/p/{id}/medidas/nova'              => [302, 302, 302, 200, 200],
        '/portal/conta'                            => [302, 302, 302, 200, 200],
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

        $year = (int) date('Y');
        $child = $this->makePatient([
            'full_name' => 'Crianca Fumaca', 'sex' => 'F', 'birth_date' => ($year - 8) . '-03-10',
            'responsible_professional_id' => $admin['id'], 'created_by' => $admin['id'],
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

        $this->fx = [
            'users' => ['M' => $admin, 'N' => $nutri, 'S' => $sec, 'R' => $resp, 'P' => $pac],
            'patient' => ['M' => $child, 'N' => $child, 'S' => $child, 'R' => $child, 'P' => $adult],
            'measure' => ['M' => $mChild, 'N' => $mChild, 'S' => $mChild, 'R' => $mChild, 'P' => $mAdult],
            'entry' => ['M' => $eChild, 'N' => $eChild, 'S' => $eChild, 'R' => $eChild, 'P' => $eAdult],
            'secretaria_id' => (int) $sec['id'],
        ];
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
                'entryId' => (string) $this->fx['entry'][$role],
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
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }
}
