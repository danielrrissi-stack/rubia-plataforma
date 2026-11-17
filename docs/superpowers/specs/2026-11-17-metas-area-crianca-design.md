# Metas e área da criança (Fase 3a) — design

Módulo 7 da especificação geral (`2026-11-01-plataforma-rubia-design.md`), com o sigilo do adolescente aplicado às
metas. Decidido em conversa com o responsável pelo projeto em 2026-11-17. A Fase 3 foi dividida: 3a = metas e área da
criança (esta), depois medicamentos (Módulo 5) e diário de hábitos (Módulo 6), cada um com seu desenho. Itens marcados
*escolha minha* foram propostos por mim e não discutidos em detalhe.

## 1. Intenção e porquê

O que mais falta hoje é **manter a criança engajada entre as consultas**. Sucesso = a família (e, quando a médica quiser,
a própria criança/adolescente) abre o app durante a semana, marca as metas combinadas na consulta, vê estrelas e selos
crescendo e lê mensagens curtas adequadas à idade; na consulta, a equipe vê o painel das últimas semanas e conversa
sobre ele em vez de perguntar de memória.

## 2. Objetivos, não-objetivos, anti-objetivos

**Objetivos**
- Metas por paciente, criadas pela equipe a partir de uma biblioteca de metas prontas, com frequência e período.
- Marcação simples "fiz" pela família (ou pela criança com login), nos **últimos 7 dias** (hoje e 6 dias anteriores).
- Estrelas e selos calculados das marcações; área lúdica da criança.
- Login próprio **opcional** para a criança/adolescente, criado pela médica; padrão = uso pelo celular dos pais.
- Sigilo do adolescente: configuração por paciente + meta marcada como sigilosa.
- Cartões educativos por faixa etária: 40 cartões escritos por mim, todos **rascunho** até a médica aprovar.
- Painel de progresso para a equipe na ficha.

**Não-objetivos (agora)**
- Medicamentos e diário de hábitos (fases seguintes).
- Metas com número a digitar (copos, minutos) — só "fiz/não fiz".
- Prêmios dados manualmente pela médica, ranking entre pacientes, notificações por e-mail/WhatsApp.
- Vídeos ou upload de imagem nos cartões *(escolha minha: emoji/ícone por cartão basta nesta fase; imagem fica no backlog)*.
- Família criar ou editar metas.

**Anti-objetivos**
- Criança/adolescente com login vendo qualquer dado do prontuário (crescimento, exames, plano, cadastro de outros).
- Meta sigilosa aparecendo para o responsável (tela, contagem de estrelas, selos, painel do portal).
- Nutricionista vendo metas que não são de alimentação/hidratação, ou pacientes não liberados a ela.
- Linguagem que culpe ou envergonhe a criança (peso, "gordo", "dieta", comparação com outras crianças). Os textos falam
  de hábitos, nunca de peso ou aparência.
- Estrela/selo que some sem motivo visível (só some se a marcação que o gerou for desmarcada).

## 3. Restrições (herdadas)

Mesmas das fases anteriores: cPanel compartilhado, PHP 8.1+ puro, sem Composer/Node em produção; CSP estrita sem inline;
migração numerada (próxima: `010_...`); auditoria de leitura e escrita; exclusão lógica; `Policy::METAS` já existe na
matriz (médico vê/edita). Fuso America/Sao_Paulo — "hoje" é a data local.

## 4. Abordagens consideradas

- **Conquistas calculadas a partir das marcações (escolhida).** Banco guarda metas, marcações, biblioteca e cartões;
  estrelas e selos são calculados na exibição. Desmarcar dentro dos 7 dias simplesmente remove a estrela.
- **Estrelas/selos gravados como registros próprios.** Permitiria prêmios manuais no futuro, mas cada correção exigiria
  recalcular e retirar prêmios já vistos. Descartada.

Quem usa: **C** — área lúdica pelo celular dos pais (padrão) + login próprio opcional para a criança maior.

## 5. Como funciona

### 5.1 Biblioteca de metas *(escolha minha nos detalhes)*
- Metas prontas com: texto para a família, texto para a criança (lúdico, opcional — se vazio usa o da família), emoji,
  categoria e frequência sugerida. Categorias: **alimentação**, **hidratação**, **sono**, **atividade**, **telas**,
  **rotina**. Alimentação e hidratação são "metas nutricionais" (a nutricionista vê e edita); as demais só a médica.
- Já vem com ~20 metas prontas escritas por mim (ex.: "Beber água em todas as refeições" / "Bebi água nas refeições
  💧"; "Comer uma fruta no lanche"; "Brincar ao ar livre"; "Desligar a tela 1 hora antes de dormir"; "Dormir no
  horário combinado"), editáveis e desativáveis pela equipe.

### 5.2 Metas da paciente
- A equipe cria a partir da biblioteca (ou texto livre), ajusta o texto, escolhe frequência — **todo dia** ou **N vezes
  por semana** (1–7) — data de início (padrão: hoje) e de fim (opcional; padrão: sem fim) e, para adolescente com login,
  a opção **sigilosa**.
- Encerrar meta = data de fim; o histórico de marcações continua visível no painel. Nada é apagado (exclusão lógica para
  erro de cadastro).
- Semana = segunda a domingo *(escolha minha)*.

### 5.3 Marcação
- Família ou criança marca/desmarca **fiz** por meta e dia, para hoje e os 6 dias anteriores, só dentro do período da
  meta. Fora disso, o servidor recusa.
- Cada marcação guarda quem marcou (usuário e papel) e quando. Desmarcar apaga a marcação (com auditoria).
- Meta "N vezes por semana": cada dia marcado conta; a semana está cumprida com N marcações.

### 5.4 Estrelas e selos *(escolha minha)*
- 1 estrela por marcação. Total exibido = estrelas desde sempre (metas encerradas incluídas).
- Selos (calculados):
  - **Primeira estrela**;
  - **10, 50, 100, 250 estrelas**;
  - **Semana completa** — numa semana (seg–dom) já terminada, todas as metas ativas nela foram cumpridas (diária: todos
    os dias do período dentro da semana; N/semana: N marcações);
  - **3 semanas seguidas** e **8 semanas seguidas** completas.
- Metas sigilosas contam para o adolescente, mas **não** entram em nenhum número mostrado ao responsável.

### 5.5 Área da criança (portal)
- No portal, item **"Minhas metas"** (para o responsável: "Metas de <nome>"), com a tela lúdica: saudação com o primeiro
  nome, total de estrelas, metas da semana com os 7 dias marcáveis, selos conquistados, e "Para você ler" com os cartões
  publicados da faixa etária.
```
  Oi, Ana! 🌟  Você já tem 23 estrelas
  Semana de 17 a 23/11
  💧 Bebi água nas refeições     [✓][✓][✓][ ][ ][ ][ ]
  🌳 Brinquei ao ar livre         [✓][✓][ ][ ][ ][ ][ ]  2 de 3
  Conquistas: 🏅 Primeira estrela  🏅 10 estrelas
  Para você ler: 💧 Por que beber água?   🍎 Lanche esperto
```
- Botões grandes, alto contraste, funcionando no celular e sem JS (cada marcação é um formulário POST; JS opcional só
  para não recarregar a página). Semanas anteriores: navegação "semana anterior" só para ver.
- A criança com login próprio vê **só** esta área (e trocar a própria senha). Nada de crescimento, plano, exames.

### 5.6 Login próprio da criança/adolescente *(escolha minha nos detalhes)*
- Criado pela **médica** na ficha (seção Acesso): papel `crianca`, vinculado ao paciente (`patients.user_id` hoje é usado
  pela conta do paciente adulto/`paciente`; o builder decide entre reutilizar esse vínculo ou criar um próprio, garantindo
  1 conta de criança por paciente).
- Crianças muitas vezes não têm e-mail: o login aceita **nome de usuário** (ex.: `ana.souza`) além de e-mail. A médica
  define usuário e senha inicial e entrega à família; a criança/família pode trocar a senha. Sem 2FA para este papel.
- Desativar o login a qualquer momento (auditoria). O alerta dos 18 anos (Fase 1) continua valendo.

### 5.7 Sigilo do adolescente
- Por paciente com login de criança: opção **"Responsáveis veem as marcações feitas pelo próprio paciente"** (padrão:
  sim). Se não: o responsável vê as metas (não sigilosas) e as marcações feitas por ele mesmo, mas não as do adolescente;
  estrelas/selos mostrados ao responsável contam só o que ele pode ver.
- Meta **sigilosa**: invisível ao responsável em toda parte; visível ao adolescente e à equipe (marcada "sigilosa").
- Mudanças nessas opções são auditadas.

### 5.8 Painel da equipe
- Na ficha, cartão **Metas** (médica; nutricionista vê só metas nutricionais dos pacientes liberados): metas ativas e o
  cumprimento das últimas 4 semanas por meta (ex.: "5/7", "2/3 ✓"), estrelas e último registro da família.
- Página `/pacientes/{id}/metas`: gerenciar metas + grade de 8 semanas.

### 5.9 Cartões educativos
- Cartão: título, texto (até ~600 caracteres), emoji, faixa etária (**3–6**, **7–10**, **11–14**, **15–19 anos**; um
  cartão pode valer para mais de uma faixa), status **rascunho/publicado**, quem aprovou e quando.
- **40 cartões** pré-escritos por mim, distribuídos entre as faixas e os temas água, frutas e verduras, lanches, sono,
  telas, brincar/movimento, rotina de refeições e emoções/comer com fome de verdade — todos como **rascunho**.
  Linguagem positiva, sem falar de peso, sem marcas comerciais, sem orientação de dose/dieta específica.
- A equipe (médica) revisa, edita e publica/despublica em **Cartões educativos** (menu). Só publicados aparecem no portal.

## 6. Dados (esboço; estrutura final a critério do builder)
- `goal_templates`: textos (família/criança), emoji, categoria, frequência sugerida, ativo.
- `patient_goals`: paciente, textos, emoji, categoria, `times_per_week` (7 = todo dia), início, fim, sigilosa, criado
  por, excluída.
- `goal_checks`: meta, data, marcado por (usuário), papel de quem marcou, quando; único (meta, data).
- `edu_cards`: título, texto, emoji, faixas, status, aprovado por/em.
- Configuração de sigilo por paciente (coluna ou tabela).
- Conta de criança: usuário com papel `crianca` + `username` (e-mail opcional para esse papel).
- Seeds: biblioteca de metas e 40 cartões (upsert sem sobrescrever edições, como o catálogo de exames).

## 7. Permissões e auditoria
- `Policy::METAS`: médico vê/edita tudo; nutricionista vê/edita só categorias alimentação/hidratação dos pacientes
  liberados; secretária nada; responsável e paciente **view** (+ marcar); criança **view METAS** só do próprio paciente.
- Marcar exige acesso ao paciente (PatientScope) e meta visível a quem marca (sigilo).
- Auditar: criar/editar/encerrar meta, marcar/desmarcar, criar/desativar login da criança, mudar sigilo, editar/publicar
  cartão; leitura do painel da equipe.

## 8. Testes que importam
- Janela de 7 dias (hoje, D-6 aceito; D-7 e amanhã recusados; fora do período da meta recusado), com fuso.
- Semana completa com meta iniciada no meio da semana; N/semana; metas encerradas; selos de sequência.
- Sigilo: responsável não vê meta sigilosa nem a conta em estrelas; opção "não vê marcações do adolescente".
- Criança logada: 403/redirect em toda rota fora da área dela; não acessa outro paciente.
- Nutricionista: só metas nutricionais.
- Cartões: rascunho nunca aparece no portal; faixa etária pela idade na data de hoje.

## 8a. Decisões complementares (após a leitura do revisor) *(todas escolha minha)*
1. **Isolamento da conta de criança:** além da política negar tudo por padrão, um **guarda global** (middleware) deixa o
   papel `crianca` acessar só: a área de metas do próprio paciente, marcar/desmarcar, ler cartões, trocar a própria
   senha e sair; qualquer outra rota → redireciona para a área dele (GET) ou 403 (POST). Controllers que hoje tratam
   `crianca` como "família" (Dashboard, Growth, Patients, Portal) passam a excluí-lo — testado rota a rota.
2. **Vínculo da conta de criança:** coluna própria (ex.: `patients.child_user_id`, única), separada de `patients.user_id`
   (conta `paciente`). Um paciente pode ter as duas, mas a tela da médica só oferece criar a de criança se não houver
   conta `paciente`. **Aos 18 anos** vale o alerta existente; a opção "transferir a conta para o paciente" passa a, quando
   houver conta de criança, convertê-la em `paciente` (mesmo usuário, marcações preservadas).
3. **Login por usuário:** `users.email` passa a aceitar nulo só para `crianca` (validado na aplicação); nova coluna
   `username` única, global, sem `@` (letras minúsculas, números, ponto; 3–40). A tela de entrar continua uma só: o
   campo aceita e-mail **ou** usuário. Bloqueio por tentativas igual ao do e-mail.
4. **Senha da criança:** sem e-mail, só a médica redefine (gera senha provisória na ficha). Senha provisória obriga a
   troca no primeiro acesso.
5. **Contagem por quem vê:** o cálculo de estrelas e selos recebe "quem está vendo" e considera só metas e marcações
   visíveis a ele. Os números do responsável podem ser menores que os do adolescente — é o efeito pretendido do sigilo.
6. **Nutricionista:** vê e cria metas só de alimentação/hidratação (também em texto livre), só desses pacientes; o cartão
   da ficha mostra só essas metas e o cumprimento delas, sem total de estrelas. Só a médica muda a categoria de uma meta.
7. **Faixa dos cartões:** pela idade de hoje; abaixo de 3 ou acima de 19 anos → nenhum cartão. **Paciente adulto**
   (`paciente`, 20+) também vê e marca as metas, com os textos para família (sem linguagem infantil e sem cartões).
8. **Marcações:** só família/criança marcam (a equipe não marca nem retroage). Se o período da meta for encurtado depois,
   marcações fora do novo período deixam de contar (não são apagadas).
9. **Seeds:** a biblioteca de metas entra **ativa** (é usada só pela equipe); os 40 cartões entram como **rascunho**.

## 9. Fica a critério do builder
Layout fino (seguindo o portal existente), nomes de rotas/classes, ícones, a forma de guardar faixas dos cartões e o
vínculo da conta de criança, desde que cumpra §5–§8.
