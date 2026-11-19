# Manual de utilização — design

Data: 2026-11-19 · Branch: `manual-uso` (a partir de `fase1`)

## Intenção

Cada pessoa que usa a plataforma precisa aprender a usá-la sem depender de explicação da Dra. Rubia. A equipe
nova (secretária, nutricionista) aprende sozinha, a médica consulta quando esquecer algo, e as famílias
recebem instruções claras, inclusive antes do primeiro acesso. Pedido da usuária: "manual de utilização
completo, com imagens e explicações de todo o sistema", **um manual para cada tipo de usuário**, nas páginas do
sistema **e** em PDF para baixar (opção C), com aviso de que **os dados de exemplo são fictícios**.

## Objetivos

- Seis manuais, um por perfil: médica, nutricionista, secretária, responsável, paciente adulto, criança.
- Cada manual mostra só o que aquele perfil enxerga (segue a matriz de acesso de `Policy.php` e os menus reais).
- Link **"Ajuda"** no menu do painel clínico e no topo do portal; abre o manual do perfil de quem está logado.
- Botão **"Baixar PDF"** em cada manual.
- Imagens reais das telas, tiradas de uma cópia local com pacientes fictícios.
- Aviso visível de dados fictícios: no topo de cada manual, na capa do PDF e na legenda de cada imagem.
- Fotos refazíveis de uma vez por um comando, quando as telas mudarem.

## Não-objetivos

- Editor do manual dentro do sistema (o texto fica no código; mudanças vêm num pacote novo).
- Vídeos, busca no texto, tradução.
- Manual público sem login (o PDF é enviado pela médica).

## Anti-objetivos (seria fracasso mesmo funcionando)

- **Qualquer dado real de paciente nas imagens ou no PDF.** As fotos nunca são tiradas de rubia.med.br.
- O script de dados fictícios rodar contra o banco de produção.
- O manual da família/criança usar jargão clínico (o portal já evita: "Linguagem simples, sem jargao clinico").
- Manual mostrar a um perfil telas que ele não pode abrir (ex.: nutricionista vendo Medicamentos/Exames/Evolução).
- Pacote de release inchar a ponto de atrapalhar o deploy (meta: imagens + PDFs ≤ 8 MB no total).

## Restrições

- PHP 8.1, MVC próprio, CSP `default-src 'self'` (sem JS inline), `asset()` para CSS/JS, MySQL 5.7 na hospedagem.
- Nada de dependência nova: Dompdf já está em `lib/dompdf`; Chrome e Node 24 já estão na máquina de
  desenvolvimento (só usados localmente, nunca no servidor).
- Nada em `public_html/` fora de `public_html/rubia.med.br/`; deploy segue o roteiro de sempre.
- Testes: `php tools/phpunit.phar` e `node --test "tests/js/*.test.js"` verdes.

## Abordagens consideradas

- **A) Só páginas no sistema.** Sempre atualizado, mas não serve para mandar à família antes do primeiro acesso.
- **B) Só PDFs.** Fácil de enviar, mas envelhece e precisa ser reenviado.
- **C) Páginas no sistema + PDF para baixar — escolhida pela usuária.** Cobre a consulta no dia a dia e o envio
  por WhatsApp. Mesmo conteúdo alimenta as duas saídas.

## Como fica (decidido)

### Conteúdo

- Organizado **por tarefa** ("Como cadastrar um paciente", "Como registrar uma pesagem"), não por tela.
- Cada manual: aviso de dados fictícios, índice, depois seções. Cada seção: título, uma ou mais imagens com
  legenda ("Exemplo com dados fictícios."), passos numerados curtos e, quando útil, uma dica.
- **Toda seção começa no ponto de vista de quem lê.** Primeira seção de todos: "Entrar pela primeira vez"
  (convite por e-mail, criar senha, código de segurança no celular — exceto criança, que não usa 2FA).
- Manual da criança: curto, frases simples, mais imagem que texto.
- Fotos da equipe em largura de computador; fotos de família, paciente e criança em largura de celular.

### Seções por perfil (ponto de partida; o construtor ajusta lendo o código)

- **Comum à equipe:** entrar pela primeira vez; trocar senha e código de segurança; buscar e abrir paciente;
  página do paciente (cartões); ditado por voz.
- **Médica:** tudo da equipe + cadastrar paciente e responsáveis; acesso da família e conta da criança;
  acesso de profissionais e maioridade; prontuário (registrar, revisar, versões); medidas e curvas de
  crescimento; gasto de energia; plano alimentar (texto e estruturado, modelos, trechos, publicar, PDF);
  exames (lançar, anexos, liberar à família, catálogo); tratamento (receita, doses, efeitos adversos, PDF da
  receita, cabeçalho da receita); metas, biblioteca de metas e cartões educativos; diário (pedidos,
  comentários); agenda (marcar, remarcar, meus horários, avisos); linha do tempo; meta de peso; painel de
  evolução (curva × doses, aviso de estabilização, critério por paciente); administração (equipe, backups) —
  seções de administração só aparecem se a médica for administradora.
- **Nutricionista:** equipe + o que a matriz permite: medidas/crescimento, energia, plano alimentar, alimentos,
  modelos e trechos, metas, diário, agenda, linha do tempo, meta de peso. Sem medicamentos, exames, evolução.
- **Secretária:** equipe + cadastro de paciente e responsáveis, agenda completa, o que mais a matriz liberar.
- **Responsável:** entrar; escolher a criança; início do portal; registrar pesagem em casa; crescimento;
  alimentação (e imprimir); exames liberados; tratamento (marcar dose, relatar efeito); metas e cartões;
  diário (anotar, foto); minha conta.
- **Paciente adulto:** igual ao responsável, sem "escolher a criança", falando "você".
- **Criança:** entrar com a senha; minhas metas e marcar o que fez; cartões; diário (se pedido).

### Onde fica

- Novo módulo `app/Help/` com controller, views e o conteúdo de cada manual em arquivos PHP que devolvem
  **dados** (lista de seções com título, imagens, passos, dica). As páginas HTML e o PDF saem do mesmo conteúdo.
  Seções comuns são definidas uma vez e reutilizadas pelos manuais que as têm.
- Rotas: `GET /ajuda` (manual do perfil logado), `GET /ajuda/{perfil}` (só médica administradora pode abrir os
  outros perfis — para treinar a equipe e enviar o PDF à família); outros perfis tentando ver manual alheio
  recebem 403.
- "Ajuda" entra no menu de `layout.php` e no topo de `layout_portal.php` (todos os perfis logados).
- Imagens em `public/assets/manual/<perfil>/<seção>.png` (ou `.jpg`, escolha do construtor dentro da meta de
  tamanho).
- **PDFs gerados na máquina de desenvolvimento** por um comando (`tools/manual_pdf.php`), usando o
  `PdfRenderer` existente com as imagens embutidas, e enviados no pacote em `public/assets/manual/pdf/`.
  O botão "Baixar PDF" aponta para esse arquivo. Motivo: evita gastar memória da hospedagem gerando PDF com
  dezenas de imagens a cada clique. Os PDFs ficam acessíveis por URL sem login; isso é aceitável porque só
  contêm dados fictícios e instruções.
- Capa do PDF: "Manual — <perfil>", data, e o aviso de dados fictícios em destaque.

### Dados fictícios e fotos

- `tools/demo_seed.php`: cria num banco **local separado** (ex.: `rubia_demo`) uma clínica fictícia completa:
  uma conta de cada perfil, ~4 pacientes (criança com obesidade acompanhada há meses, adolescente, adulto em
  tirzepatida com doses subindo e platô recente, um paciente novo), com medidas, prontuário, plano, exames,
  receitas, metas, diário, consultas — o suficiente para todas as telas aparecerem cheias e todos os avisos
  (ex.: "estabilizou") aparecerem. Nomes claramente inventados.
  **Trava de segurança:** o script recusa rodar se o host do banco não for `localhost`/`127.0.0.1` ou se o
  nome do banco não for o banco de demonstração.
- `tools/manual_shots.mjs` (Node, sem pacotes): abre o Chrome local em modo sem janela, entra com cada conta
  fictícia (calculando o código de segurança a partir do segredo conhecido da seed), visita a lista de telas
  de cada manual e salva as imagens. Lista de telas = mesma lista de seções do conteúdo (um arquivo de
  roteiro diz qual URL e qual largura cada imagem usa).
- Ordem para refazer tudo: subir o banco local → `demo_seed.php` → servidor local `php -S` → `manual_shots.mjs`
  → `manual_pdf.php` → testes → pacote.
- Credenciais fictícias ficam só no código da seed local; nunca vão no pacote de release (o build exclui).

## Testes

- Cada perfil abre `/ajuda` com 200 e vê o próprio manual; o link "Ajuda" aparece nos dois layouts.
- `/ajuda/{perfil}`: médica administradora vê todos; demais recebem 403 em manual alheio; perfil inválido 404.
- Aviso de dados fictícios presente na página e em cada legenda.
- Toda imagem citada no conteúdo existe em `public/assets/manual/`; todo PDF citado existe.
- Manual da nutricionista não cita Medicamentos, Exames nem Evolução (amarra o anti-objetivo de acesso).
- `demo_seed.php` recusa host/banco que não sejam de demonstração.
- `SmokeAllRoutesTest` ganha as rotas novas.
- Build: release não inclui `demo_seed.php`, `manual_shots.mjs` nem o roteiro.

## Deixado ao construtor

- Texto exato de cada seção (seguindo os princípios acima e conferindo cada passo contra a tela real).
- Formato e compressão das imagens, dentro da meta de 8 MB.
- Layout visual da página de ajuda (usando `.cartao` e o estilo existente).
- Detalhes do roteiro de fotos (espera por gráficos do Chart.js antes da foto, etc.).

## Deploy

Sem migração. Copiar o conteúdo de `public/` (inclui `assets/manual/`) e refazer a linha do `index.php`.
