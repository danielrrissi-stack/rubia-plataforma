# Exames laboratoriais (Fase 2b) — design

Módulo 4 da especificação geral (`2026-11-01-plataforma-rubia-design.md`). Decidido em conversa com o responsável pelo
projeto em 2026-11-17. Itens marcados *escolha minha* foram propostos por mim e aceitos sem discussão detalhada.

## 1. Intenção e porquê

A Dra. Rubia recebe os laudos dos pacientes **pelo WhatsApp** (PDF ou foto). Hoje não há onde registrar os valores de forma
comparável. Na consulta, ela precisa ver de relance **o que mudou desde a última coleta e o que está fora da faixa** — para
uma criança, comparando com faixas pediátricas, não com a faixa de adulto que muitos laboratórios imprimem.
Sucesso = abrir a paciente e ver numa tabela a evolução de glicemia, HbA1c, lipídios, transaminases etc., com os alterados
destacados e o laudo original a um clique; e a família ver, quando liberado, os resultados com uma frase simples.

## 2. Objetivos, não-objetivos, anti-objetivos

**Objetivos**
- Registrar coletas (data, laboratório, laudo anexo) com os resultados que vieram — só os que vieram.
- Catálogo de exames configurável (nome, unidade, faixas por idade/sexo), já carregado com os exames da especificação.
- Classificar cada resultado (normal / limítrofe / alterado) pela idade e sexo na data da coleta; HOMA-IR calculado.
- Tabela de evolução e gráfico por exame com faixa de referência.
- Liberação por coleta para a família, que vê valor + frase simples.

**Não-objetivos (agora)**
- Família enviar laudos pelo portal (opção B da conversa — fica para depois, se fizer falta).
- Integração com laboratórios ou leitura automática (OCR) do laudo.
- Gráficos de exames no portal da família.
- Exames de imagem / laudos descritivos sem valor numérico (pode-se anexar o arquivo, mas sem resultado estruturado).

**Anti-objetivos (seria falha mesmo funcionando)**
- Laudo acessível por URL direta sem login, ou a partir da pasta pública.
- Nutricionista ver qualquer coisa de exames (especificação geral, linha 60: nutricionista não vê exames laboratoriais).
- Família ver coleta não liberada, o laudo anexo ou o HOMA-IR.
- Classificar criança com faixa de adulto (ou vice-versa) sem avisar.
- Faixa de referência inventada sem fonte: toda faixa pré-carregada cita a fonte e fica "a confirmar" até a médica revisar.

## 3. Restrições (herdadas)

Mesmas da Fase 2a: cPanel compartilhado, PHP 8.1+ puro, sem Composer/Node em produção; CSP estrita sem inline; migrações
numeradas (próxima: `009_...`); auditoria de leitura e escrita de dado clínico; exclusão lógica; Chart.js local já usado
pelas curvas de crescimento (`public/assets/js/growth-chart.js`). **Primeiro módulo com envio de arquivos**: a pasta
`storage/uploads` já existe (criada pelo instalador, fora da raiz pública) e já entra no backup criptografado.

## 4. Abordagens consideradas

- **Catálogo + coletas + resultados (escolhida).** Tabelas normalizadas: exame do catálogo, faixas, coleta, resultado
  (uma linha por exame por coleta), anexo. Tabela de evolução, gráfico e classificação saem de consultas simples; exame
  novo cadastrado pela médica funciona igual aos pré-carregados.
- **Coleta como bloco JSON** (como a estrutura do plano). Bom para algo lido inteiro; ruim aqui, porque gráfico e tabela
  precisam de um exame através de todas as coletas.
- **Colunas fixas para os nove exames.** Mais rápido, mas viola "outros configuráveis" e deixa exames novos sem gráfico.

Quem envia o laudo: **só a equipe** (opção A). Faixas: **pré-carregadas das diretrizes, editáveis** (opção 1).
Família: **valores com indicação simples**.

## 5. Como funciona

### 5.1 Onde fica
- Página da paciente: cartão **Exames** (médico; não aparece para nutricionista/secretária) com a última coleta e link
  "Ver exames".
- `/pacientes/{id}/exames`: tabela de evolução + lista de coletas + botão "Nova coleta".
- Menu da equipe: **Catálogo de exames** (médico) para editar exames e faixas.

### 5.2 Nova coleta / editar coleta
- Campos: data da coleta (obrigatória, não futura, não anterior ao nascimento), laboratório (texto livre, opcional),
  observação (opcional), anexos (0..n; PDF, JPEG, PNG; até 10 MB cada *(escolha minha)*).
- Lista dos exames ativos do catálogo, agrupados (Glicemia/insulina; Lipídios; Fígado; Outros), cada um com campo de
  valor e a unidade ao lado; vazio = não veio. "+ outro exame" mostra exames ativos fora da lista padrão.
- Valor numérico com vírgula ou ponto; resultados qualitativos ("< 5", "indetectável") aceitos como texto, sem
  classificação nem gráfico *(escolha minha)*. O número é gravado como digitado (DECIMAL com folga); as casas decimais do
  catálogo valem só para exibição.
- Se a validação falhar, os valores digitados voltam preenchidos, mas os arquivos precisam ser escolhidos de novo (aviso
  claro na tela). Envio maior que o limite do PHP (`post_max_size`) chega sem dados: detectar pelo `CONTENT_LENGTH`
  antes da checagem de CSRF e mostrar "arquivo grande demais (máx. X MB)" em vez de "sessão expirou".
- Ao salvar, mostra a classificação calculada. Editar mantém auditoria do antes/depois; excluir é lógico.

### 5.3 Classificação
- Faixas por exame, com recorte por sexo (M/F/ambos) e idade (de/até, em meses). Cada faixa define os limites de
  **normal**, **limítrofe** e **alterado**, em um ou nos dois sentidos (ex.: HDL é alterado quando **baixo**).
- Idade = idade na data da coleta. Se nenhuma faixa cobre a idade/sexo, o valor aparece **sem classificação** com
  aviso "sem faixa para esta idade" — nunca cai numa faixa de outra idade.
- **HOMA-IR** = glicemia (mg/dL) × insulina (µU/mL) / 405, calculado quando a mesma coleta tem os dois; mostrado como
  linha própria na tabela, marcado "calculado". Só para a equipe.
- **Regra dos limites** *(escolha minha)*: cada faixa guarda **pontos de corte**, não intervalos, como as diretrizes
  publicam ("< 110 desejável, 110–129 limítrofe, ≥ 130 alto"). Lado alto: `limítrofe_alto` e `alterado_alto`; lado baixo:
  `limítrofe_baixo` e `alterado_baixo`; qualquer um pode ser nulo (HDL só tem lado baixo). O corte **pertence à
  categoria pior**: v ≥ alterado_alto → alterado alto; senão v ≥ limítrofe_alto → limítrofe alto; v ≤ alterado_baixo →
  alterado baixo; senão v ≤ limítrofe_baixo → limítrofe baixo; senão normal. Para cortes publicados como "< 40" no
  lado baixo, o seed grava o maior valor ainda alterado na precisão do exame (ex.: HDL "< 40" com 0 casas → 39) — o
  builder documenta essa conversão no SOURCES.md. Faixas do mesmo exame e sexo não podem se sobrepor em idade
  (validação ao salvar); faixa "ambos" e faixa de sexo específico não convivem para a mesma idade. Lacunas = sem faixa.
- Sexo e data de nascimento são obrigatórios no cadastro do paciente (`patients.sex`, `birth_date` NOT NULL), então a
  faixa sempre pode ser escolhida quando existe.
- HOMA-IR é **calculado na hora**, só nas telas da equipe (não é gravado); só calcula se glicemia estiver em mg/dL e
  insulina em µU/mL (exames identificados por um código fixo no catálogo, ex.: `glicemia`, `insulina`); com outra
  unidade, não mostra.
- Faixas pré-carregadas ficam com o selo **"a confirmar"** até a médica abrir o exame no catálogo e confirmar; enquanto
  isso a classificação aparece normalmente, com o selo visível no catálogo (não poluir a tabela da consulta).

### 5.4 Faixas pré-carregadas (a pesquisar e citar)
O builder pesquisa e registra em `data/exams/SOURCES.md`, com referência completa, a fonte de cada faixa. Ponto de partida
(confirmar nas fontes; números abaixo são indicativos, não definitivos):
- Lipídios 2–19 anos: Atualização da Diretriz Brasileira de Dislipidemias (SBC 2017) / NHLBI 2011 — CT, LDL, HDL,
  não-HDL, triglicerídeos (0–9 e 10–19 anos com limites diferentes).
- Lipídios adultos: mesma diretriz SBC (valores referenciais desejáveis em jejum).
- Glicemia de jejum e HbA1c: SBD/ADA (normal / pré-diabetes / diabetes) — mesmas faixas para criança e adulto.
- TGP (ALT) pediátrica: NASPGHAN 2017 (limite por sexo); TGO e TGP adulto: limite usual citado com fonte.
- Insulina de jejum e HOMA-IR: **não há consenso pediátrico único** — pré-carregar sem faixa (sem classificação) e
  deixar a médica definir; registrar isso no SOURCES.md.
Exames sem fonte confiável entram **sem faixa**, nunca com número inventado.

### 5.5 Tabela de evolução e gráfico *(escolha minha, aceita)*
- Linhas = exames que têm algum resultado (na ordem do catálogo), colunas = coletas (mais recente à esquerda), mostrando
  as últimas 6 com "ver todas". Célula: valor + marca (▲ alterado alto, ▼ alterado baixo, ◆ limítrofe) — a marca nunca
  é só cor (acessibilidade). Cabeçalho da coluna leva à coleta (anexos, edição, liberação).
- Clicar no exame abre o gráfico (Chart.js local) do valor no tempo, com a faixa normal sombreada quando a faixa não
  muda no período; se a faixa muda (ex.: passou dos 10 anos), mostrar os pontos coloridos pela classificação de cada
  data em vez da faixa sombreada.

### 5.6 Laudos (primeiro envio de arquivos)
- Gravados em `storage/uploads/exames/AAAA/MM/<aleatório>.<ext>`; nome original guardado no banco só para exibição.
- Validar tipo pelo conteúdo (finfo), não pela extensão; recusar o resto. Sem execução: a pasta fica fora da raiz
  pública e tem `.htaccess` negando acesso.
- Download só por rota autenticada que confere permissão (médico com acesso à paciente), com cabeçalhos
  `Content-Disposition`, `X-Content-Type-Options: nosniff` e `Cache-Control: private, no-store`. Leitura auditada.
- Excluir anexo = exclusão lógica (o arquivo permanece para o backup/auditoria).
- Verificar `upload_max_filesize`/`post_max_size` do servidor e mostrar mensagem clara se o arquivo for maior.

### 5.7 Família
- Médico marca a coleta como **liberada** (e pode desfazer); quem/quando fica registrado.
- Portal: item **Exames** (só aparece se houver coleta liberada) com a lista de coletas liberadas, cada uma com
  exame, valor, unidade e frase: "dentro do esperado", "um pouco acima do esperado" (limítrofe alto), "acima do
  esperado", "abaixo do esperado", e para HDL baixo "abaixo do esperado". Sem classificação → só o valor.
- Família não vê: laudo, HOMA-IR, observação interna, coletas não liberadas, gráficos.

### 5.8 Catálogo de exames
- Lista de exames (nome, unidade, grupo, ordem, ativo). Médico cria/edita; não apaga exame com resultados (desativa).
- Faixas editáveis por exame (sexo, idade de/até, limites). Mudança de faixa reclassifica **na exibição**
  (classificação é calculada, não gravada) *(escolha minha)* — o histórico mostra sempre a classificação pela faixa
  atual; a auditoria guarda a mudança de faixa.
- Unidade é do exame; o valor é digitado já nessa unidade (sem conversão automática nesta fase).

## 6. Dados (esboço; estrutura final a critério do builder)

- `lab_tests` (catálogo): nome, unidade, grupo, ordem, casas decimais, ativo, `reference_confirmed`.
- `lab_ranges`: exame, sexo (M/F/null), idade_min_meses, idade_max_meses, limites normal/limítrofe (baixo e alto),
  fonte.
- `lab_collections`: paciente, data, laboratório, observação, liberada_em/por, criado/alterado por, excluído.
- `lab_results`: coleta, exame, valor numérico (DECIMAL) ou texto qualitativo; único por (coleta, exame).
- `lab_attachments`: coleta, caminho interno, nome original, mime, tamanho, sha256, enviado por/em, excluído.
- Seeds com o catálogo e as faixas (upsert idempotente, como os da TACO).

## 7. Permissões e auditoria
- Seção `Policy::EXAMES` já existe: médico vê/edita; nutricionista e secretária não. O médico acessa todos os pacientes
  (`PatientScope`), então "médico sem acesso" no teste significa paciente excluído/inexistente.
- Família *(escolha minha)*: `responsavel` e `paciente` (conta própria do paciente) ganham **view EXAMES** na matriz;
  `crianca` continua sem nada. Como a matriz não expressa "só liberadas", as **telas da equipe exigem papel médico**
  explicitamente (como `Nutrition\Staff::assert` faz no plano alimentar) além do Gate, e o portal consulta **só coletas
  liberadas** em um método de repositório próprio — nenhuma rota da equipe pode depender só de `Policy::allows`.
  Teste: responsável com acesso à paciente recebe 403 em toda rota `/pacientes/{id}/exames…` e no download do laudo.
  O sigilo do adolescente (Fase 3) poderá restringir isso depois; nesta fase, liberar = a médica decidiu mostrar.
- Retenção: exclusão é lógica e o arquivo fica (backup e auditoria). Apagamento definitivo por pedido LGPD fica para a
  Fase 4 (exportação/LGPD) *(escolha minha)*.
- Auditar: criar/editar/excluir coleta e resultado (antes/depois), liberar/desfazer, envio/download/exclusão de anexo,
  edição de exame e faixa, leitura da página de exames e do portal.

## 8. Testes que importam
- Classificação: limites inclusivos/exclusivos nas bordas, HDL baixo, troca de faixa aos 10 anos, sem faixa → sem
  classificação, sexo específico vs ambos.
- HOMA-IR só com glicemia e insulina da mesma coleta.
- Anexo: tipo falso (PHP renomeado .pdf) recusado; download negado sem login, para nutricionista, para família, e para
  médico sem acesso à paciente.
- Portal: coleta não liberada invisível; HOMA-IR e laudo nunca aparecem.

## 9. Fica a critério do builder
Layout fino das telas (seguindo o padrão das telas existentes), nomes de classes/rotas, agrupamento visual do formulário,
textos de ajuda, e a forma exata de armazenar limites, desde que cubra os casos de §5.3.
