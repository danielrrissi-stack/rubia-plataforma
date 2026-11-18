# Tratamento e medicamentos (Fase 3b) — design

Módulo 5 da especificação geral (`2026-11-01-plataforma-rubia-design.md`). Em 2026-11-17 o responsável pelo projeto
aprovou as propostas abaixo ("siga direto com as propostas acima"), sem discutir os detalhes. Itens marcados *escolha
minha* foram decididos por mim.

## 1. Intenção e porquê

A médica precisa ter, num só lugar, **o que o paciente está tomando, desde quando, em que dose e por quê a dose mudou**.
A família precisa ver o tratamento em linguagem simples, anotar cada dose dada (importante nas aplicações semanais, como
tirzepatida e semaglutida) e contar efeitos colaterais sem esperar a consulta. Sucesso: na consulta, a médica abre a ficha
e vê a linha do tempo das doses, as aplicações registradas pela família e os relatos de efeitos. A família, no celular,
sabe qual é a dose atual e marca "dei a dose" em dois toques.

## 2. Objetivos, não-objetivos, anti-objetivos

**Objetivos**
- Prescrições por paciente: medicamento, via, dose, frequência, orientação para a família, início, fim, observações
  internas e data da próxima avaliação.
- Mudança de dose = **novo registro** (data em que vale, nova dose/frequência/orientação, motivo, profissional). O
  histórico fica sempre visível.
- Família registra a administração (data/hora; a dose é a prescrita naquela data) quando a médica ativa isso na prescrição.
- Efeitos adversos e intercorrências registrados pela equipe e relatados pela família. A médica marca "ciente" e pode
  anotar gravidade e conduta.
- Prescrição **sigilosa** para paciente menor de 18 anos (só a médica marca): os responsáveis não veem a prescrição, as
  doses registradas nem os relatos ligados a ela.
- Receita simples para imprimir (o navegador salva em PDF), com cabeçalho configurável e linha para assinatura à mão.
- Na tela inicial da médica: relatos da família ainda não vistos e reavaliações vencidas ou para os próximos 7 dias.

**Não-objetivos (agora)**
- Receita de controle especial (duas vias, modelo próprio) e assinatura digital ICP-Brasil.
- Lembretes ou avisos de dose para a família (e-mail, notificação).
- Lista oficial de medicamentos (ANVISA). O nome é digitado; o campo sugere os nomes já usados *(escolha minha)*.
- Estoque, lote, local de aplicação estruturado (vai no campo "observação" do registro de dose).
- Criança/adolescente com login próprio vendo o tratamento: a conta `crianca` continua só nas metas.

**Anti-objetivos**
- **Qualquer cálculo, sugestão, comparação ou alerta de dose.** Dose e frequência são texto livre, guardados e exibidos
  como digitados. O sistema não interpreta números, não diz "aumentou" nem "próximo passo".
- Prescrição sigilosa deixando rastro para o responsável: contagem, cartão "Tratamento" no início, relato ligado a ela,
  dose registrada.
- Nutricionista ou secretária vendo qualquer dado de medicamentos.
- Apagar de verdade um registro clínico. Toda exclusão é lógica e auditada.
- A família achar que o relato de efeito substitui atendimento de urgência.

## 3. Restrições (herdadas)

As mesmas das fases anteriores: cPanel compartilhado, PHP 8.1+ puro, sem Composer/Node em produção e nenhuma biblioteca
nova; CSP estrita; migração numerada (próxima: `011_medications.sql`); auditoria de leitura e escrita; fuso
America/Sao_Paulo. `Policy::MEDICAMENTOS` já existe (o médico vê e edita).

## 4. Abordagens consideradas

- **Prescrição + linhas de dose (escolhida).** A prescrição guarda o que não muda (medicamento, via, período). Cada dose
  é uma linha com a data em que passa a valer. A dose atual é a última linha com data até hoje. O histórico de
  alterações é a própria lista, e os registros de administração guardam a dose daquele dia.
- **Cada alteração cria uma nova prescrição encadeada à anterior.** Funciona como as versões do prontuário, mas espalha
  um mesmo tratamento em vários itens e complica os registros da família e a receita. Descartada.
- **Uma linha editada, com o histórico só na auditoria.** É a mais simples, mas o histórico de doses fica escondido, e a
  especificação pede cada alteração como evento visível. Descartada.

Receita: **página própria para impressão** (mesmo padrão de `/planos/{id}/imprimir`), em vez de gerar PDF no servidor.
O navegador salva em PDF e o servidor não precisa de biblioteca nova.

## 5. Como funciona

### 5.1 Prescrição
- Campos: **medicamento** (até 120 caracteres, obrigatório); **via** (oral, sublingual, subcutânea, intramuscular,
  tópica, inalatória, nasal, oftálmica, otológica, retal, vaginal, outra); **início** (obrigatório); **fim** (opcional,
  ≥ início); **próxima avaliação** (opcional); **família registra as doses** (sim/não, padrão não); **sigilosa** (só
  médica, só para paciente menor de 18 anos na data de hoje; uma vez ligada, nunca é desligada implicitamente: só a médica a desmarca, deliberadamente, mesmo depois dos 18 anos); **observações internas** (nunca aparecem para a família).
- A primeira linha de dose nasce com a prescrição, com data = início: **dose** (até 60 caracteres, ex.: "2,5 mg"),
  **frequência** (até 120, ex.: "1 vez por semana"), **orientação para a família** (opcional, até 1000).
- **Alterar dose:** nova linha com data em que passa a valer (≥ data da última linha, ≥ início e ≤ hoje; se o início
  ainda não chegou, a data pode ser o próprio início), dose, frequência, orientação e **motivo** (opcional, até 200).
  *(Escolha minha: data futura não é aceita além do início, para o sistema não virar uma agenda de escalonamento.)*
- **Editar** corrige erros de digitação nos campos da prescrição, mas não nas linhas de dose. Uma dose errada se corrige
  com uma nova linha.
- **Encerrar:** grava a data de fim (≥ início e ≤ hoje) e o motivo (opcional). Uma prescrição encerrada não recebe novas
  doses nem registros da família.
- **Excluir** (lançada por engano): exclusão lógica com confirmação. Some das listas, da família e da receita.
- Situação calculada: **futura** (início > hoje), **ativa** e **encerrada** (fim < hoje).

### 5.2 Registro de administração (família)
- Só `paciente` e `responsavel`, só em prescrição visível a ele, **ativa na data registrada** e com "família registra"
  ligado.
- Campos: data e hora (padrão: agora) e observação (opcional, até 200 caracteres, ex.: "barriga, lado direito"). A dose
  gravada é a dose da linha vigente naquela data e não pode ser editada.
- Janela: de 7 dias atrás até agora; não aceita hora futura *(escolha minha, igual às metas)*.
- O mesmo usuário enviando a mesma prescrição e o mesmo minuto duas vezes (duplo clique) grava um único registro, sem
  erro.
- Quem registrou pode **remover** o próprio registro dentro da janela de 7 dias (exclusão lógica, auditada).

### 5.3 Efeitos adversos e intercorrências
- **Família:** relata data (de 90 dias atrás até hoje), descrição (até 2000 caracteres) e, se quiser, a qual medicamento
  se refere (entre os que ela vê). Aviso fixo no formulário: "Este relato não é visto na hora. Em caso de urgência,
  procure atendimento ou um pronto-socorro."
- **Equipe (médica):** registra com data, descrição, medicamento (opcional), gravidade (leve/moderada/grave, opcional) e
  conduta (opcional).
- **Ciente:** a médica marca "ciente" num relato da família e pode, no mesmo envio, anotar gravidade e conduta.
- A família vê só os relatos feitos pela família, nunca os da equipe nem a conduta. Relatos ligados a prescrição
  sigilosa não aparecem para o responsável.

### 5.4 Quem vê o quê
| Papel | Equipe (telas `/pacientes/...`, `/prescricoes/...`) | Portal |
|---|---|---|
| médico | tudo, inclusive sigilosas | — |
| nutricionista, secretária | nada (403) | — |
| responsável | — | prescrições não sigilosas, doses registradas (de qualquer membro da família) dessas prescrições, relatos da família não ligados a sigilosa |
| paciente (adulto) | — | tudo do próprio tratamento, inclusive o que foi sigiloso enquanto menor |
| criança (login próprio) | — | nada (guarda `childarea` já prende nas metas) |

Telas da equipe exigem papel `medico` **antes** do Gate, como os exames: a família ganha `view MEDICAMENTOS` para o
portal e, sem essa checagem, abriria as telas da equipe. No portal, a família vê dose, frequência, orientação, início,
fim e próxima avaliação; **nunca** as observações internas, o motivo da mudança nem o autor.

### 5.5 Telas da equipe
- **Ficha do paciente:** cartão "Tratamento" com as prescrições ativas (nome e dose atual), a próxima avaliação mais
  próxima e o número de relatos da família não vistos.
- **Tratamento do paciente:** prescrições ativas e futuras, depois as encerradas (recolhidas); relatos de efeitos; botões
  "Nova prescrição", "Registrar efeito", "Receita".
- **Prescrição:** dados, histórico de doses (data, dose, frequência, orientação, motivo, profissional), registros da
  família dos últimos 90 dias (data/hora, dose, quem registrou, observação) e efeitos ligados a ela.
- O campo medicamento sugere (`<datalist>`) os nomes já usados em prescrições anteriores.

### 5.6 Receita
- **Cabeçalho**, configurado pela administradora em `/admin/receita`: nome do consultório, endereço, telefone e cidade
  (chaves `rx_clinic_name`, `rx_address`, `rx_phone`, `rx_city` na tabela `settings`). O nome e o CRM vêm do usuário
  médico que imprime (`users.name`, `users.professional_register`).
- Tela de escolha: prescrições ativas e futuras do paciente, todas marcadas. "Gerar receita" abre a página de impressão.
- Página de impressão: "Receituário", nome do paciente, itens numerados agrupados por via ("Uso oral", "Uso
  subcutâneo"...), cada um com medicamento, dose, frequência e orientação; "cidade, data de hoje"; linha de assinatura
  com nome e CRM. Botão "Imprimir" (`print-button.js`, já existente). Não mostra observações internas.
- Leitura auditada (`view`, `prescriptions`, com `print`).

### 5.7 Portal da família
- Cartão **"Tratamento"** no início, só quando há prescrição visível ativa ou futura.
- Página **Tratamento**: para cada prescrição visível ativa/futura: nome, via, dose atual, frequência, orientação, início,
  próxima avaliação; botão **"Registrar dose"** quando ligado; últimas doses registradas (7 dias) com "remover" nas
  próprias. Encerradas nos últimos 30 dias aparecem como "encerrado em ...". Link **"Contar um efeito ou
  intercorrência"** e a lista dos relatos da família.
- Linguagem simples: "dose", "como usar", "próxima avaliação com a Dra. Rubia".

### 5.8 Tela inicial da médica
- "Relatos da família para ver": relatos da família sem "ciente", do mais antigo para o mais novo, com link para o
  tratamento.
- "Reavaliações": prescrições ativas com próxima avaliação vencida ou nos próximos 7 dias.

## 6. Auditoria
- `create`/`update`/`end`/`delete` em `prescriptions`; `create` em `prescription_doses` (alteração de dose);
  `create`/`delete` em `medication_logs`; `create`/`review` em `adverse_events`; `update` em `settings` (cabeçalho da
  receita).
- Leitura das telas da equipe: `('view', 'prescriptions', null|id, patientId, null, ['section' => 'medicamentos'])`;
  a impressão da receita acrescenta `'print' => true`.

## 7. Fica a critério do construtor
- Nomes de tabelas e classes, layout das telas (seguindo o padrão das telas de exames e metas).
- Se o histórico de doses fica numa tabela ou numa linha do tempo.

## 8. Escolhas registradas
1. Receita simples impressa pelo navegador (sem biblioteca de PDF). Controle especial fica fora.
2. Prescrição sigilosa só para menores de 18 anos, marcada só pela médica (como as metas). O sigilo nunca é desligado implicitamente (regra R6): editar a prescrição depois dos 18 anos o mantém; só a médica o desmarca. Ligar continua exigindo médica e menor de 18.
3. Data de dose futura só até o início da prescrição (sem agenda de escalonamento).
4. Janela de 7 dias para a família registrar ou remover doses; relatos de efeito até 90 dias atrás.
5. Relatos da família aparecem na tela inicial da médica até ela marcar "ciente".
6. `medicamentos_em_uso` do prontuário (texto livre do Módulo 1) continua como está: é a anamnese, inclusive de remédios
   receitados por outros médicos. O módulo novo trata do que a Dra. Rubia prescreve.
