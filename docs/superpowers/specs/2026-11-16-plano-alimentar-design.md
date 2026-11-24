# Plano alimentar (Fase 2a) — design

**Data:** 2026-11-16
**Base:** especificação geral `docs/superpowers/specs/2026-11-01-plataforma-rubia-design.md`, Módulo 3.
**Status:** design para aprovação.
**Escopo desta etapa:** só o plano alimentar. Exames laboratoriais (Módulo 4) são a etapa seguinte (Fase 2b), com design próprio.

## 1. Intenção e porquê

A médica (e a nutricionista, nos pacientes liberados para ela) precisa entregar o plano alimentar na própria consulta, de forma
rápida, e a família precisa ver esse plano no app no dia a dia. O pedido do usuário: **"fácil de usar, que dê inclusive para colar
um texto pronto, o mais próximo possível do Easy Health"**.

O Easy Health (referência principal) oferece dois modos de plano: **Estruturado** (busca alimentos na TACO e calcula) e
**Qualitativo** (texto livre), ambos salváveis como **modelos** reutilizáveis, mais receitas e cadastro de alimentos próprios.

Sucesso = em poucos minutos a profissional monta (ou cola, ou parte de um modelo) o plano, publica, e a família abre o portal e vê
o que comer em cada refeição, com as trocas possíveis, sem jargão.

## 2. Objetivos, não-objetivos, anti-objetivos

**Objetivos**
- Dois modos de plano, como no Easy Health: **Texto** e **Estruturado**.
- No modo Texto, **colar texto pronto** (Word, WhatsApp, PDF copiado, outro sistema) sem quebrar a formatação básica.
- No modo Estruturado: refeições com horário; alimentos da TACO com quantidade e medida caseira; **trocas nas duas formas**
  (substitutos por alimento e opções de refeição inteira); somatório em tempo real comparado à meta de energia.
- **Modelos** reutilizáveis (de plano completo e de trechos de texto/orientações).
- **Versões**: publicar um plano torna-o vigente; o anterior fica no histórico, nunca é apagado.
- Família vê o plano vigente no portal, legível no celular, e consegue imprimir/salvar em PDF.

**Não-objetivos (nesta etapa)**
- Plano diferente por dia da semana / cardápio semanal. **Decidido pelo usuário: o plano é o mesmo todos os dias, com opções de troca.**
  (Logo, não há "duplicar dia".)
- Lista de compras, micronutrientes, receitas com cálculo nutricional (receitas entram só como texto, ver §5.6).
- Reconhecimento automático de alimentos a partir de texto colado ("importar de texto") — possível extensão futura.
- Gerador de PDF no servidor (fica para a Fase 4, junto com a exportação do prontuário).

**Anti-objetivos**
- Família ver números técnicos que confundam (kcal, gramas de macro) ou qualquer dado clínico.
- Nutricionista acessar plano de paciente não liberado a ela.
- Plano publicado ser alterado ou apagado sem deixar a versão anterior.
- Texto colado virar porta para script malicioso (XSS) no portal da família.

## 3. Restrições (herdadas)

cPanel compartilhado, PHP 8.1+ puro, sem Composer/Node em produção, bibliotecas versionadas no repositório; CSP estrita
(`default-src 'self'`, sem script/estilo inline — ver `fix: remove estilos e handlers inline das views (CSP)`); migrações SQL
numeradas (próxima: `008_...`); auditoria de leitura e escrita de dado clínico; exclusão lógica.

## 4. Abordagens consideradas

| | Abordagem | Troca |
|---|---|---|
| **A (escolhida pelo usuário)** | Dois modos, como o Easy Health: Texto (colar) + Estruturado (TACO, cálculo) | Mais trabalho, cobre os dois jeitos de trabalhar |
| B | Um modo só, texto "inteligente" que reconhece alimentos e calcula | Erra muito com texto livre ("1 concha média", "à vontade"); corrigir é pior que montar |
| C | Só modo Texto agora, Estruturado depois | Mais rápido, mas sem cálculo, que a especificação exige |

## 5. Como funciona (decidido na conversa, salvo quando marcado *escolha minha*)

### 5.1 Onde fica
Nova seção **"Plano alimentar"** na ficha do paciente (ao lado de Energia/Crescimento): mostra o plano vigente, rascunho em
andamento (se houver), histórico de versões e o botão "Novo plano". Permissão: seção `nutricao` já existente em `Policy`
(médico e nutricionista veem e editam; nutricionista só nos pacientes liberados via `PatientScope`).

### 5.2 Ciclo de vida *(escolha minha)*
- **Rascunho → Publicado → Substituído ou Encerrado.** Pode haver no máximo um rascunho e um vigente por paciente.
- Rascunho é editável à vontade e invisível para a família. "Publicar" congela o conteúdo, marca como vigente e marca o anterior
  como substituído (data). Para alterar um publicado: "Editar" cria um rascunho copiado dele.
- **Encerrar plano** (decidido pelo usuário): retira o plano vigente do portal sem publicar outro (paciente errado, erro sério,
  saída do tratamento). Exige motivo curto; registra quem/quando/motivo. A família passa a ver "Nenhum plano alimentar no
  momento". O plano encerrado continua no histórico.
- Um plano publicado nunca é editado nem apagado; só substituído ou encerrado. Rascunho pode ser descartado (exclusão lógica).
- "Novo plano" quando já existe rascunho: a tela oferece "Continuar o rascunho" ou "Descartar e começar outro". *(escolha minha)*
- Ao criar um plano novo, o usuário escolhe: em branco (Texto ou Estruturado), **a partir de um modelo**, ou **a partir do plano
  vigente**.
- Cada plano registra quem criou/publicou e quando (assinatura, como no prontuário). Auditoria de leitura e escrita.

### 5.3 Modo Texto
- Título do plano + corpo em texto + orientações.
- **Colar texto pronto** é o caso principal. *Escolha minha:* o editor é uma **área de texto simples** (não um editor visual
  tipo Word), com uma convenção leve de formatação e **pré-visualização ao lado**:
  - linha terminada em `:` ou toda em maiúsculas → título de refeição (ex.: `CAFÉ DA MANHÃ (7h):`);
  - linha começando com `-`, `*`, `•`, `·` ou número+`)`/`.` → item de lista;
  - `**texto**` → negrito;
  - linha em branco → novo parágrafo.
  Motivo: texto colado do Word/WhatsApp chega com quebras de linha e marcadores (`•`), que essa convenção já reconhece; um
  editor visual exigiria biblioteca grande, quebraria a CSP e abriria risco de HTML malicioso. O texto é guardado como texto puro
  e convertido para HTML **no servidor**, com escape total (nenhum HTML do usuário passa adiante).
- Botões de inserção rápida acima da área (inserem texto, não formatação oculta): "Título de refeição", "Item", "Negrito",
  e **"Inserir trecho de modelo"** (ver §5.5).
- Ao colar, normalizar: trocar tabulações por espaço, remover espaços no fim das linhas, reduzir 3+ linhas vazias para 1,
  converter marcadores Unicode comuns para item de lista.

### 5.4 Modo Estruturado
- **Refeições**: nome + horário opcional, em ordem arrastável (ou setas ↑↓ *escolha minha, mais simples e acessível*).
  Refeições sugeridas ao criar em branco: Café da manhã, Lanche da manhã, Almoço, Lanche da tarde, Jantar, Ceia (removíveis).
- **Opções de refeição**: cada refeição tem 1 ou mais opções (Opção 1, Opção 2…). A maioria terá só uma; a tela mostra opções
  extras só quando a profissional clica "Adicionar opção".
- **Itens**: em cada opção, alimentos buscados pelo nome (busca com tolerância a acento, digitando ≥ 2 letras), com
  **quantidade + unidade**: gramas/ml ou uma **medida caseira** do alimento (ex.: 2 × "colher de sopa cheia" = 50 g).
  Campo de observação curto por item (ex.: "sem açúcar").
- **Substitutos por item**: cada item pode ter uma lista de "ou …" (outro alimento + quantidade + medida). *Escolha minha:*
  a profissional escolhe os substitutos manualmente (busca igual à do item); o sistema mostra, ao lado de cada substituto, as
  kcal dele vs. do item original, para ela avaliar a equivalência. Sem sugestão automática de equivalentes nesta etapa.
- **Texto livre por refeição**: campo opcional "observação da refeição" (ex.: "fruta à vontade", "evitar suco").
- **Orientações gerais** do plano: mesmo editor do modo Texto (§5.3).
- **Quadro de totais** (fixo no topo, atualiza ao digitar, *cálculo no navegador com os valores da TACO enviados à página e
  recalculado no servidor ao salvar*): kcal, proteína, carboidrato, gordura, fibra; % de kcal de cada macro; comparação com a
  **meta de energia vigente** do paciente (tabela `energy_goals`, campo `get_kcal`): valor, diferença e barra. Sem meta: aviso
  "Paciente sem meta de energia" com link para a seção Energia.
  - **O total do dia usa a Opção 1 de cada refeição** (decidido). Cada opção mostra o seu próprio total (kcal e macros), para a
    profissional comparar opções entre si. Substitutos não entram no total.
- Salvar é explícito (botão "Salvar rascunho") *e* automático a cada 30 s se houver mudança *(escolha minha, para não perder
  trabalho; sem conflito porque só uma pessoa edita o rascunho — se duas abrirem, vale o último salvamento e a tela avisa que o
  rascunho mudou desde que foi aberto)*.

### 5.5 Modelos
- **Modelo de plano**: guarda um plano completo (Texto ou Estruturado), com título. Criado por "Salvar como modelo" a partir de
  qualquer plano, ou em uma tela própria "Modelos de plano". Usar um modelo copia o conteúdo para um rascunho novo do paciente
  (alterar o modelo depois não muda planos já criados).
- **Trecho de texto** (*escolha minha, cobre o "colar texto pronto" do dia a dia*): blocos de texto curtos e nomeados
  (ex.: "Orientações lanche escolar", "Hidratação", "Como montar o prato") inseridos com um clique no modo Texto ou nas orientações.
- Modelos e trechos são **compartilhados entre a equipe** (médica e nutricionistas veem e usam todos); editar/excluir: quem
  criou ou a admin. Exclusão lógica. *(escolha minha)*

### 5.6 Base de alimentos
- **TACO 4ª edição (NEPA/UNICAMP, 2011)** importada no banco: nome, grupo, e por 100 g: kcal, proteína, carboidrato, lipídio,
  fibra. Valores "Tr"/"NA"/"*" da TACO viram 0 (traço) ou nulo (não analisado), conforme a nota da tabela; nulo conta como 0 no
  total e o item é marcado "valor não disponível na TACO".
- **Medidas caseiras**: da **Tabela de Medidas Referidas para os Alimentos Consumidos no Brasil (POF/IBGE 2008–2009)**,
  associadas aos alimentos TACO correspondentes (nome da medida + gramas). A correspondência TACO↔POF não é 1:1; o builder faz
  o casamento para os alimentos mais usados e deixa os demais só com gramas. *(escolha minha)*
- **Alimentos e medidas próprios** ("Minha tabela", como no Easy Health): a equipe cadastra alimento (nome + valores por 100 g)
  e adiciona/edita medidas caseiras de qualquer alimento. Marcados como "próprio" na busca.
- Receitas: nesta etapa entram como **alimento próprio** (a profissional informa os valores por 100 g ou por porção) ou como
  texto. Receita calculada a partir de ingredientes fica para depois.
- Os dados de referência ficam em `data/taco/` e `data/pof/` (arquivos-fonte) + seed SQL gerado por script em `tools/`,
  como já é feito com OMS e PA (`tools/import_who.php`, `tools/import_bp.php`), e o instalador carrega o seed.
  Para instalações já existentes (rubia.med.br já instalado): a migração `008` cria as tabelas e o seed é aplicado pelo
  `tools/migrate.php` ou importado no phpMyAdmin (atualizar README §9).

### 5.7 O que a família vê
- No portal do paciente, novo cartão/tela **"Alimentação"**: o plano vigente, por refeição, em linguagem simples:
  - nome da refeição e horário;
  - itens como frase caseira: "2 colheres de sopa cheias de arroz branco" (se não houver medida caseira, "50 g de …");
  - trocas como "ou …" logo abaixo do item; opções de refeição como "Opção 1 / Opção 2";
  - observações e orientações.
  - **Sem kcal, macros, gramas de macro nem meta de energia.** (Proposta registrada; o usuário não se opôs.)
- Plano em modo Texto: mostra o texto formatado (§5.3).
- Botão **"Imprimir / salvar PDF"**: página com estilo de impressão (cabeçalho com nome da Dra. Rubia e do paciente, data de
  publicação, profissional responsável) e o `window.print()` do navegador.
- Rascunhos e histórico **não** aparecem para a família.
- Permissão: acrescentar `nutricao` à visão de `paciente` e `responsavel` em `Policy`, **limitada pelo controller ao plano
  vigente** (como já é feito com medidas de origem família). Criança: sem acesso nesta etapa (área da criança é Fase 3).
- Adolescente com sigilo (Fase 3) não é afetado: plano alimentar não é item sigiloso nesta etapa.

### 5.8 Impressão para a equipe
A tela do plano na ficha tem a mesma página de impressão, com opção de incluir ou não o quadro de totais.

## 6. Dados (esboço; estrutura final a critério do builder)

- `foods` (TACO + próprios: origem, nome, nome normalizado p/ busca, grupo, kcal/prot/carb/lip/fibra por 100 g, criado_por, ativo)
- `food_measures` (alimento, nome da medida, gramas, origem POF/própria)
- `meal_plans` (paciente, modo texto|estruturado, título, status rascunho|publicado|substituído, corpo texto, orientações,
  totais calculados congelados na publicação, criado_por/em, publicado_por/em, substituído_em, excluído_em)
- `meal_plan_meals` (plano, nome, horário, ordem, observação) → `meal_plan_options` (refeição, número) →
  `meal_plan_items` (opção, alimento, quantidade, medida ou g, gramas resolvidas, observação, ordem) →
  `meal_plan_substitutes` (item, alimento, quantidade, medida, gramas)
- `meal_plan_templates` (+ cópia da mesma estrutura, ou JSON do conteúdo — a critério do builder) e `text_snippets`
- `meal_plans` inclui também: encerrado_por/em e motivo do encerramento.
- Na publicação, guardar por item o **nome do alimento, o nome da medida e os valores nutricionais usados** (snapshot), para que
  uma correção ou renomeação futura na tabela de alimentos não altere planos já entregues. *(escolha minha: snapshot por item,
  não só o total)*
- Alimentos da TACO e alimentos próprios não podem colidir de id: o seed da TACO usa ids fixos numa faixa reservada (ou chave
  natural `origem + código TACO`) e nunca sobrescreve alimentos próprios ao ser recarregado. *(escolha minha)*

## 6a. Auditoria *(escolha minha)*

Registra: abrir o plano (leitura de dado clínico), criar rascunho, salvar rascunho manualmente, publicar, encerrar, descartar,
salvar/alterar modelo e trecho, cadastrar/alterar alimento e medida próprios. O **salvamento automático** de 30 s não gera uma
linha por salvamento: grava no máximo uma linha "rascunho atualizado" por sessão de edição (ou a cada 10 min), para não inundar
a trilha.

## 6b. Frases para a família *(escolha minha)*

As medidas caseiras guardam forma singular e plural (ex.: "colher de sopa cheia" / "colheres de sopa cheias"); quando a fonte
não trouxer o plural, a equipe pode preenchê-lo, senão usa-se "2 × colher de sopa cheia".

## 7. Cálculos e testes

- Nutriente do item = valor por 100 g × gramas / 100; gramas = quantidade × gramas da medida (ou a própria quantidade se g/ml).
  *Escolha minha:* ml tratado como g (densidade 1) com aviso na ajuda; a TACO é por 100 g.
- % de macro = (prot×4, carb×4, lip×9) / soma dessas kcal.
- Testes PHPUnit obrigatórios: somatório por opção/refeição/dia (Opção 1), medidas caseiras, valores nulos, arredondamento,
  conversor texto→HTML (incluindo tentativa de injeção `<script>`, `javascript:`, HTML colado), regras de permissão
  (nutricionista sem liberação, família só vê vigente, família não vê rascunho nem plano encerrado), ciclo
  rascunho/publicar/substituir/encerrar.
- Conferência do importador TACO contra alguns valores publicados (ex.: arroz tipo 1 cozido, feijão carioca cozido, pão francês).

## 8. Fica a critério do builder

Estrutura exata de tabelas/rotas/pastas, layout detalhado (seguindo o estilo atual), JS da tela estruturada (sem framework,
arquivo em `public/assets/js/`, compatível com a CSP), estratégia de busca (LIKE em nome normalizado basta para ~600 alimentos),
divisão em tarefas e ordem de entrega (sugestão: base de alimentos → modo Texto + publicação + portal → modo Estruturado → modelos).
