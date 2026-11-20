# Manual de utilização Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Seis manuais (um por perfil) dentro do sistema em `/ajuda`, com fotos de telas tiradas de uma clínica fictícia local e um PDF pronto por perfil.

**Architecture:** Conteúdo como dados PHP em `app/Help/content/` (uma lista de seções por perfil), lido por `App\Help\Manual`. A mesma lista alimenta a página (`HelpController`), o roteiro das fotos (`tools/manual_shots_list.php` → `tools/manual_shots.mjs`, Chrome local via DevTools) e o PDF (`tools/manual_pdf.php`, `PdfRenderer` existente). Dados fictícios vêm de `tools/demo/DemoSeed.php` num banco local `rubia_demo`, escolhido por `APP_ENV=demo`.

**Tech Stack:** PHP 8.1 (MVC próprio), Dompdf 3.1.6 vendorizado, Node 24 sem pacotes (WebSocket e crypto nativos), Chrome local só na máquina de desenvolvimento.

**Spec:** `docs/superpowers/specs/2026-11-19-manual-uso-design.md`

## Global Constraints

- Perfis e slugs de URL/arquivo: `medico→medica`, `nutricionista→nutricionista`, `secretaria→secretaria`, `responsavel→responsavel`, `paciente→paciente`, `crianca→crianca`.
- Títulos: "Manual da médica", "Manual da nutricionista", "Manual da secretária", "Manual do responsável", "Manual do paciente", "Manual da criança".
- Aviso no topo de cada manual e na capa do PDF, texto exato: **"Todos os nomes, pacientes e números deste manual são fictícios, criados só para mostrar como o sistema funciona."**
- Legenda abaixo de cada imagem termina com: **"Exemplo com dados fictícios."**
- Fotos nunca de rubia.med.br; só do servidor local com `APP_ENV=demo`.
- `DemoSeed` só roda pela CLI se `db.host` ∈ {`localhost`, `127.0.0.1`} **e** `db.name === 'rubia_demo'`.
- Imagens: JPEG em `public/assets/manual/<slug>/<arquivo>.jpg`; computador 1280 px de largura, celular 390 px.
- PDFs: `public/assets/manual/pdf/manual-<slug>.pdf`.
- `public/assets/manual/` inteiro (imagens + PDFs) ≤ 8 MB.
- Sem JS inline (CSP `default-src 'self'`); CSS via `asset()`; nada de dependência nova.
- Release não inclui `tools/demo/`, `tools/manual_shots.mjs`, `tools/manual_shots_list.php`, `tools/manual_pdf.php`, `config/config.demo.php`.
- Família/paciente/criança: linguagem simples, sem jargão clínico. Nutricionista: sem Medicamentos, Exames, Evolução.
- Testes: `source tools/env.sh; php tools/phpunit.phar` (nunca dois ao mesmo tempo) e `node --test "tests/js/*.test.js"`.
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; nunca `git commit -a`; não adicionar `o que fazer.txt` nem `COLAR/`.

## Review Focus

1. Criança logada abrindo `/ajuda` — a guarda `childarea` redireciona tudo fora das metas; esperado: abre o manual da criança (teste em Task 2).
2. Família com termo de consentimento pendente abrindo `/ajuda` — esperado: vai para `/consentimento` como qualquer página (comportamento atual de `consent`; teste em Task 2).
3. Médica **não** administradora em `/ajuda/responsavel` — esperado: 403, e o próprio manual sem as seções de administração (teste em Task 2).
4. Conteúdo citando imagem que não foi fotografada ou URL de foto com marcador sem valor no `ids.json` — esperado: falha no teste/no roteiro com o nome do arquivo, não imagem quebrada (testes em Task 1, Task 4 e Task 5).
5. Seed rodado duas vezes — esperado: recria do zero (apaga e remonta o banco `rubia_demo`), sem duplicar contas (teste em Task 3).

---

### Task 1: Modelo do manual e validação do conteúdo

**Files:**
- Create: `app/Help/Manual.php`
- Create: `app/Help/content/medico.php`, `nutricionista.php`, `secretaria.php`, `responsavel.php`, `paciente.php`, `crianca.php`, `_equipe.php`, `_familia.php`
- Test: `tests/Unit/HelpManualTest.php`

**Interfaces:**
- Produces:
  - `Manual::ROLES` = `['medico','nutricionista','secretaria','responsavel','paciente','crianca']`
  - `Manual::slug(string $role): string`, `Manual::roleFromSlug(string $slug): ?string`, `Manual::title(string $role): string`
  - `Manual::NOTICE` (aviso exato), `Manual::CAPTION_SUFFIX = 'Exemplo com dados fictícios.'`
  - `Manual::sections(string $role, bool $withAdmin): array` → lista de seções:
    `['id'=>string, 'title'=>string, 'intro'=>?string, 'steps'=>list<string>, 'tip'=>?string, 'admin'=>bool,
      'images'=>list<['file'=>string /* "<slug>/<nome>.jpg" */, 'caption'=>string, 'url'=>string, 'as'=>?string, 'width'=>'desktop'|'mobile']>]`
  - `Manual::allImages(): list<array>` — todas as imagens dos seis manuais, com `role` acrescentado (consumido por Task 4/5).
- Arquivos de conteúdo devolvem `return [ ...seções... ];`. Seções comuns ficam em `_equipe.php`/`_familia.php` como funções ou arrays reutilizados; `images[].file` sempre usa o slug do manual que inclui a seção (fotos por perfil, porque o menu muda).
- `url` aceita marcadores `{chave}` resolvidos por `storage/demo/ids.json` (Task 3). `as` = login usado na foto: um perfil, `'novo'` (conta de equipe sem 2FA, para a tela de primeiro acesso) ou `null` (deslogado).
- Nesta task o conteúdo de cada perfil tem só a seção `entrar` ("Entrar pela primeira vez"), com passos reais; o resto vem nas Tasks 5 e 6.

- [ ] **Step 1: Write the failing test** — `tests/Unit/HelpManualTest.php`:
  - `test_slugs_and_titles`: `slug('medico') === 'medica'`, `roleFromSlug('medica') === 'medico'`, `roleFromSlug('xx') === null`, `title('crianca') === 'Manual da criança'`.
  - `test_every_role_has_valid_sections`: para cada papel, `sections($r, true)` não vazio; cada seção tem `id` único no manual, `title` e `steps` não vazios; cada imagem tem `file` começando com `slug($r) . '/'` e terminando em `.jpg`, `width` ∈ {desktop, mobile}, `caption` terminando com `Manual::CAPTION_SUFFIX`, `url` começando com `/`.
  - `test_first_section_is_first_access`: `sections($r, false)[0]['id'] === 'entrar'` para todos.
  - `test_admin_sections_filtered`: nenhuma seção com `admin => true` em `sections('medico', false)`; nenhum outro papel tem seção admin.
  - `test_nutritionist_manual_never_mentions_forbidden_modules`: texto concatenado (títulos, intros, passos, dicas, legendas) de `sections('nutricionista', true)` não contém (sem diferenciar maiúsculas) `medicament`, `exame`, `evolução`, `receita`.
  - `test_family_and_child_images_are_mobile`: imagens de `responsavel`, `paciente`, `crianca` têm `width === 'mobile'`; de equipe, `desktop`.
- [ ] **Step 2: Run** `source tools/env.sh; php tools/phpunit.phar --filter HelpManualTest` — Expected: FAIL (class `App\Help\Manual` not found).
- [ ] **Step 3: Implement** `Manual` e os arquivos de conteúdo com a seção `entrar` de cada perfil (criança: entrar só com e-mail e senha, sem código; equipe: convite por e-mail → criar senha → código de segurança obrigatório; família/paciente: convite → senha → aceitar o termo → código opcional em "Minha conta"). Confira cada passo lendo `app/Auth/views` e `app/Portal/views`.
- [ ] **Step 4: Run** the same filter — Expected: PASS.
- [ ] **Step 5: Commit** `git add app/Help tests/Unit/HelpManualTest.php` · `git commit -m "feat: modelo e conteudo inicial do manual por perfil"`

### Task 2: Páginas `/ajuda`, links e acesso

**Files:**
- Create: `app/Help/HelpController.php`, `app/Help/views/index.php`
- Modify: `app/routes.php` (perto das rotas do portal), `app/Auth/ChildArea.php::allows`, `app/Layout/views/layout.php` (nav padrão), `app/Layout/views/layout_portal.php` (link no `portal-topo`), `public/assets/css/app.css` (bloco `.manual`)
- Test: `tests/Http/HelpPagesTest.php`, `tests/Http/SmokeAllRoutesTest.php`

**Interfaces:**
- Consumes: `Manual::*` (Task 1).
- Produces: `GET /ajuda` → `HelpController::index`; `GET /ajuda/{perfil:[a-z]+}` → `HelpController::show`; ambos com `$authMw`.
- Regras de `show`: slug desconhecido → 404; slug do próprio perfil → 200; outro slug → 200 só se `role === 'medico'` e `is_admin = 1`, senão 403. `index` = manual do próprio perfil. Seções admin aparecem só quando quem vê é médica administradora.
- Layout: equipe vê `layout.php`; responsavel/paciente/crianca vêem `layout_portal.php`.
- Página: `h1` com o título, aviso `Manual::NOTICE` em `<p class="aviso">`, botão "Baixar PDF" → `/assets/manual/pdf/manual-<slug>.pdf`, índice com âncoras `#<id>`, cada seção em `.cartao` com `<img src="/assets/manual/<file>" alt="<caption>" loading="lazy">` + `<figcaption>`, passos em `<ol>`, dica em `<p class="dica">`. Médica administradora vê no topo uma lista de links para os seis manuais.
- `ChildArea::allows`: libera `GET /ajuda` e `GET /ajuda/crianca`.
- Nav: `['Ajuda', '/ajuda']` como último item do menu padrão de `layout.php`; no portal, link "Ajuda" antes do botão Sair.

- [ ] **Step 1: Write the failing tests** — `HelpPagesTest` (padrão de login dos outros testes HTTP: `totp_enabled = 1` + `completeLogin`; família com termo aceito):
  - `test_each_role_opens_own_manual`: para os seis perfis, `GET /ajuda` → 200, contém `Manual::title($role)`, `Manual::NOTICE` e `manual-<slug>.pdf`.
  - `test_admin_doctor_opens_any_manual_and_sees_admin_sections`: médica `is_admin=1` em `/ajuda/responsavel` → 200 com "Manual do responsável"; `/ajuda` contém título de uma seção `admin`.
  - `test_non_admin_doctor_is_limited`: médica `is_admin=0`: `/ajuda/responsavel` → 403; `/ajuda` não contém títulos das seções admin.
  - `test_other_roles_get_403_on_foreign_manual_and_404_on_unknown`: secretária `/ajuda/medica` → 403; `/ajuda/xyz` → 404.
  - `test_child_reaches_help`: criança com conta vinculada: `/ajuda` → 200, `/ajuda/medica` → 302.
  - `test_family_with_pending_consent_is_sent_to_consent`: responsável sem termo aceito: `/ajuda` → 302 para `/consentimento`.
  - `test_help_link_in_both_layouts`: `GET /pacientes` (médica) e `GET /portal` (responsável) contêm `href="/ajuda"` (via `url()`).
- `SmokeAllRoutesTest`: em `EXPECTED`, `'/ajuda' => [200, 200, 200, 200, 200, 200]` e `'/ajuda/{perfil}' => [200, 403, 403, 403, 403, 302]`; em `concretePath`, `'perfil' => 'medica'`.
- [ ] **Step 2: Run** `php tools/phpunit.phar --filter "HelpPagesTest|SmokeAllRoutesTest"` — Expected: FAIL (404 nas rotas novas).
- [ ] **Step 3: Implement** controller, view, rotas, ChildArea, links e CSS (reaproveite `.cartao`; imagem `max-width:100%`, borda `var(--linha)`; imagens de celular com `max-width: 390px`).
- [ ] **Step 4: Run** the same filter, then the full suite — Expected: PASS, suite verde (865 + novos).
- [ ] **Step 5: Commit** `git commit -m "feat: paginas de ajuda por perfil com link no menu e no portal"`

### Task 3: Clínica fictícia (`APP_ENV=demo` + DemoSeed)

**Files:**
- Modify: `app/Core/Config.php::load` (`APP_ENV=demo` → `config/config.demo.php`), `.gitignore` (+ `config/config.demo.php`), `tools/build_release.php` (`$excludePrefixes` + `'tools/demo/'`, `'tools/manual_shots.mjs'`, `'tools/manual_shots_list.php'`, `'tools/manual_pdf.php'`, `'config/config.demo.php'`)
- Create: `tools/demo/DemoSeed.php` (classe `Tools\Demo\DemoSeed`, carregada com `require_once`), `tools/demo/seed.php` (CLI), `config/config.demo.example.php`
- Test: `tests/Integration/DemoSeedTest.php`, `tests/Unit/BuildReleaseExcludeTest.php` (se já houver teste do build, estenda-o)

**Interfaces:**
- Produces:
  - `DemoSeed::assertSafeTarget(array $db): void` — lança `RuntimeException` se `host` ∉ {localhost, 127.0.0.1} ou `name !== 'rubia_demo'`.
  - `DemoSeed::run(Database $db, string $today): array` — apaga os dados de todas as tabelas de aplicação (exceto `schema_migrations` e tabelas de referência importadas: OMS, TACO/POF, PA, catálogo de exames), monta a clínica e devolve o mapa de ids `{crianca, adolescente, adulto, novo_paciente, plano, coleta, receita, meta, pedido_diario, registro_diario, consulta, cartao, ...}`.
  - Contas (e-mails fixos `@demo.test`, nomes inventados): `medico` (admin), `nutricionista` (com liberação), `secretaria`, `responsavel` (da criança e do adolescente, termo aceito), `paciente` (o adulto, termo aceito), `crianca` (conta da criança), `novo` (equipe sem 2FA). Senha e segredo TOTP de demonstração ficam como constantes **só** em `DemoSeed` (`DemoSeed::PASSWORD`, `DemoSeed::TOTP_SECRET`), usados pelo roteiro de fotos.
  - `tools/demo/seed.php`: `APP_ENV=demo`, lê config, `assertSafeTarget`, roda o `Migrator`, `run(..., date('Y-m-d'))`, grava `storage/demo/ids.json` e `storage/demo/logins.json` (`{perfil: {email}}`).
- Conteúdo da clínica (datas relativas a `$today`): criança 9 anos com obesidade, ~10 meses de medidas (consultório + casa), metas com marcações, diário com registros, plano publicado, exames liberados; adolescente 15 anos; adulto 42 anos em tirzepatida 2,5 → 5 → 7,5 mg com perda e **estabilização nas últimas 4 semanas** e exames metabólicos; paciente novo só com cadastro; agenda com horários da médica e consultas hoje e nos próximos dias; prontuário com registros; efeito adverso relatado; cartão educativo publicado; modelos de plano e trechos. Use os repositórios/serviços do `Container` sempre que existirem.

- [ ] **Step 1: Write the failing tests** — `DemoSeedTest extends DbTestCase`:
  - `test_guard`: `assertSafeTarget(['host'=>'127.0.0.1','name'=>'rubia_demo'])` não lança; `['host'=>'db.hospedagem','name'=>'rubia_demo']`, `['host'=>'localhost','name'=>'rubia_dev']` e `['host'=>'localhost','name'=>'quali498_rubia']` lançam `RuntimeException`.
  - `test_run_creates_every_login_role`: após `run($this->db, '2026-11-19')`, existe um usuário ativo para cada um de `medico, nutricionista, secretaria, responsavel, paciente, crianca` com e-mail `@demo.test`; a médica tem `is_admin = 1`.
  - `test_adult_shows_plateau_and_child_has_curve`: `EvolutionData::build(<médica>, <adulto>, '2026-11-19', '6m')['plateau']['status'] === 'estabilizou'`; a criança tem ≥ 8 medidas e ≥ 1 de origem família.
  - `test_run_twice_does_not_duplicate`: rodar duas vezes deixa exatamente 1 usuário por e-mail de demonstração.
  - `test_release_excludes_demo_tools`: o filtro de exclusão do build exclui `tools/demo/DemoSeed.php`, `tools/manual_shots.mjs`, `config/config.demo.php` (extraia `$isExcluded` para uma função testável se preciso, ex.: `tools/build_release_filter.php`).
- [ ] **Step 2: Run** `php tools/phpunit.phar --filter "DemoSeedTest"` — Expected: FAIL (class not found).
- [ ] **Step 3: Implement** os arquivos acima. `config.demo.example.php` = cópia do exemplo com `name => 'rubia_demo'`. Crie o banco local `rubia_demo` do mesmo jeito que `rubia_dev`/`rubia_test` (ver `tools/env.sh`).
- [ ] **Step 4: Run** filter, depois a suíte inteira — Expected: PASS. Depois `APP_ENV=demo php tools/demo/seed.php` — Expected: imprime as contas criadas; `APP_ENV=demo php -S localhost:8001 -t public` abre a lista de pacientes fictícios no navegador.
- [ ] **Step 5: Commit** `git commit -m "feat: clinica ficticia local para as fotos do manual"`

### Task 4: Roteiro de fotos

**Files:**
- Create: `tools/manual_shots_list.php`, `tools/manual_shots.mjs`, `tools/manual_shots_lib.js`
- Test: `tests/js/manual-shots.test.js`, `tests/Unit/HelpShotListTest.php`

**Interfaces:**
- Consumes: `Manual::allImages()` (Task 1), `storage/demo/ids.json` e `logins.json` (Task 3), `DemoSeed::PASSWORD`/`TOTP_SECRET`.
- Produces:
  - `php tools/manual_shots_list.php` → JSON em stdout: lista de `{file, url (marcadores resolvidos), as, width}`; marcador sem valor em `ids.json` → sai com código 1 e mensagem `"Marcador {x} sem valor (arquivo <file>)"`. Função testável `manualShotList(array $images, array $ids): array` no mesmo arquivo (protegida por `if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__)`).
  - `tools/manual_shots_lib.js` (CommonJS, exporta para `node --test`): `totp(secretB32, unixSeconds) -> string` (6 dígitos, SHA-1, passo 30 s, igual a `App\Auth\Totp::code`), `viewport(width) -> {width, height}` (`desktop` → 1280×800, `mobile` → 390×844), `groupByLogin(list) -> Map<as, list>`.
  - `node tools/manual_shots.mjs [--base http://localhost:8001] [--only <slug>]`: abre `chrome.exe` (`C:\Program Files\Google\Chrome\Application\chrome.exe`) com `--headless=new --remote-debugging-port` e um perfil temporário; por login: entra pela tela `/login` (preenche e-mail/senha; se cair em `/2fa`, preenche `totp`), visita cada URL, espera a rede ficar quieta e os `<canvas>` do Chart.js desenharem, e salva `Page.captureScreenshot` JPEG qualidade 75, altura máxima 1800 px, em `public/assets/manual/<file>`. No fim, apaga o perfil temporário e encerra o Chrome.
- [ ] **Step 1: Write the failing tests**:
  - JS `totp`: com o segredo `GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ` e `59` → mesmo valor que `php -r` com `App\Auth\Totp::code` dá para o mesmo par (rode-o e fixe o número no teste); `viewport('mobile')` → `{width:390,height:844}`; `groupByLogin` junta por `as` preservando a ordem.
  - PHP `HelpShotListTest`: `manualShotList([{file:'medica/x.jpg',url:'/pacientes/{adulto}',as:'medico',width:'desktop'}], ['adulto'=>7])[0]['url'] === '/pacientes/7'`; marcador ausente lança `RuntimeException` com o nome do arquivo.
- [ ] **Step 2: Run** `node --test "tests/js/*.test.js"` e `php tools/phpunit.phar --filter HelpShotListTest` — Expected: FAIL (módulo/função inexistente).
- [ ] **Step 3: Implement** os três arquivos.
- [ ] **Step 4: Run** os dois testes — Expected: PASS. Depois, com o servidor demo no ar, `node tools/manual_shots.mjs --only medica` — Expected: grava `public/assets/manual/medica/entrar*.jpg`; abra a imagem e confira que mostra só dados fictícios.
- [ ] **Step 5: Commit** `git commit -m "feat: roteiro automatico das fotos do manual"`

### Task 5: Conteúdo completo — equipe (médica, nutricionista, secretária)

**Files:**
- Modify: `app/Help/content/_equipe.php`, `medico.php`, `nutricionista.php`, `secretaria.php`
- Create: `public/assets/manual/{medica,nutricionista,secretaria}/*.jpg` (geradas)
- Test: `tests/Unit/HelpAssetsTest.php`

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: seções listadas no spec ("Seções por perfil"). Seções `admin => true`: equipe, backups, cabeçalho da receita, avisos da agenda. Cada passo conferido contra a tela real do servidor demo (nome exato de botões e campos). Ditado por voz: uma seção na equipe.
- [ ] **Step 1: Write the failing test** — `HelpAssetsTest`:
  - `test_every_referenced_image_exists`: para cada item de `Manual::allImages()`, `is_file(public/assets/manual/<file>)`; mensagem de falha lista os que faltam.
  - `test_manual_assets_within_budget`: soma dos arquivos em `public/assets/manual/` ≤ 8 * 1024 * 1024.
- [ ] **Step 2: Run** `--filter HelpAssetsTest` depois de escrever o conteúdo da equipe — Expected: FAIL listando as imagens novas.
- [ ] **Step 3:** escrever o conteúdo e rodar `node tools/manual_shots.mjs --only medica` (e nutricionista, secretaria); revisar cada imagem (só dados fictícios, tela certa, gráfico desenhado).
- [ ] **Step 4: Run** `HelpAssetsTest`, `HelpManualTest`, `HelpPagesTest` — Expected: PASS.
- [ ] **Step 5: Commit** `git add app/Help public/assets/manual tests/Unit/HelpAssetsTest.php` · `git commit -m "docs: manuais da medica, nutricionista e secretaria"`

### Task 6: Conteúdo completo — família, paciente, criança

**Files:**
- Modify: `app/Help/content/_familia.php`, `responsavel.php`, `paciente.php`, `crianca.php`
- Create: `public/assets/manual/{responsavel,paciente,crianca}/*.jpg`

**Interfaces:**
- Consumes: Tasks 1–5.
- Produces: seções do spec. Paciente adulto fala "você" e não tem "escolher a criança". Criança: entrar, minhas metas e marcar o que fiz, cartões, minha conta — só telas que a `childarea` deixa abrir.
- [ ] **Step 1:** escrever o conteúdo; **Run** `HelpAssetsTest` — Expected: FAIL listando as imagens da família.
- [ ] **Step 2:** `node tools/manual_shots.mjs --only responsavel` (e paciente, crianca); revisar cada imagem.
- [ ] **Step 3: Run** `--filter "Help"` — Expected: PASS.
- [ ] **Step 4: Commit** `git commit -m "docs: manuais do responsavel, paciente e crianca"`

### Task 7: PDFs, README e pacote

**Files:**
- Create: `tools/manual_pdf.php`, `app/Help/views/pdf.php`
- Create: `public/assets/manual/pdf/manual-<slug>.pdf` (gerados)
- Modify: `README.md` (seção "Manual de utilização": como refazer fotos e PDFs; item de deploy "copiar public/ inclui assets/manual/")
- Test: `tests/Unit/HelpPdfTest.php`

**Interfaces:**
- Consumes: `Manual::sections($role, true)` (PDF da médica inclui as seções admin, com "(só para administradora)" no título), `PdfRenderer::render(string $title, string $bodyHtml): string` (via `Container`), `View::render('Help/views/pdf.php', [...], null)`.
- Produces: `manualPdfHtml(string $role): string` em `tools/manual_pdf.php` (testável) — capa com título, data `d/m/Y`, `Manual::NOTICE` em destaque; índice; seções com imagens embutidas como `data:image/jpeg;base64,...` (o Dompdf está com recursos remotos desligados e chroot na lib) e legendas; quebra de página antes de cada seção. A CLI grava os seis PDFs.
- [ ] **Step 1: Write the failing test** — `HelpPdfTest`: `manualPdfHtml('responsavel')` contém `Manual do responsável`, `Manual::NOTICE`, `data:image/jpeg;base64,` e não contém `src="/assets`; `test_every_pdf_exists`: os seis `manual-<slug>.pdf` existem e começam com `%PDF`.
- [ ] **Step 2: Run** `--filter HelpPdfTest` — Expected: FAIL.
- [ ] **Step 3: Implement** e rodar `php tools/manual_pdf.php`; abrir um PDF e conferir capa, imagens e aviso.
- [ ] **Step 4: Run** a suíte inteira + `node --test "tests/js/*.test.js"` + `HelpAssetsTest` (orçamento inclui os PDFs) — Expected: tudo verde. `php tools/build_release.php` — Expected: zip gerado; listar e confirmar que não tem `tools/demo/` nem `config.demo.php`.
- [ ] **Step 5: Commit** `git commit -m "feat: manuais em PDF e instrucoes no README"`
