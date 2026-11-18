# Agenda (Fase 4a) — design

Parte do Módulo 9 da especificação geral (`2026-11-01-plataforma-rubia-design.md`). A fase 4 foi dividida em três
entregas: **4a agenda** (esta), **4b linha do tempo + prontuário em PDF + exportação LGPD**, **4c mensagens**. Em
2026-11-18 o responsável pelo projeto definiu os horários, os avisos por WhatsApp copiados, a agenda por profissional
e que só a equipe marca por enquanto, e aprovou as demais propostas ("pode usar esse texto, siga com as propostas").
Itens marcados *escolha minha* foram decididos por mim.

## 1. Intenção e porquê

A clínica **não usa nenhuma agenda hoje**. A secretária e as profissionais precisam marcar consultas e retornos sem
choque de horário, e a família precisa ser avisada. Enquanto não há WhatsApp pago, a Dra. Rubia avisa cada paciente
pelo WhatsApp dela: o sistema entrega o texto pronto (num e-mail na véspera e num botão na agenda) para ela só colar e
enviar. Sucesso: a secretária marca uma consulta em poucos cliques no primeiro horário livre da profissional certa;
na véspera, cada profissional recebe um e-mail com os avisos de todos os pacientes do dia seguinte; na agenda, um
clique no botão de WhatsApp abre a conversa do paciente com a mensagem pronta.

## 2. Objetivos, não-objetivos, anti-objetivos

**Objetivos**
- **Uma agenda por profissional** (cada médica, cada nutricionista). Cada profissional monta a própria
  **semana-modelo** (dias, faixas de horário, duração da consulta), **fecha** dias ou períodos e **abre horários
  extras** em datas específicas.
- Horários iniciais da Dra. Rubia (decididos): consulta de **30 minutos**; **segunda a sexta 07:30–11:30 e
  12:30–20:00**; **sábado 07:30–12:30**. Entram prontos na agenda dela pela migração/instalação (ver §5.1).
- **Só a equipe marca** (secretária, médicas, nutricionistas). A família **vê** as próprias consultas no portal.
- Situações da consulta: agendada, confirmada, realizada, faltou, cancelada, remarcada e **reservada** (prevista para o
  futuro; ver §5.3).
- **E-mail da véspera para cada profissional** com o texto de aviso pronto de cada paciente do dia seguinte.
- **Botão de WhatsApp** em cada horário marcado (só equipe): copia o aviso para a área de transferência e abre o
  WhatsApp do paciente/responsável com a mensagem já preenchida.
- **E-mails para a família**: ao marcar, cancelar ou remarcar, e lembrete na véspera.
- Celular do responsável no cadastro (hoje só o paciente tem telefone).

**Não-objetivos (agora)**
- Família marcar ou pedir horário pelo portal (futuro: entra como "reservada"; só fica previsto no banco).
- WhatsApp automático/pago (fica para quando houver o serviço).
- Feriados automáticos, salas/consultórios, pagamentos/convênios, lista de espera, consultas por vídeo.
- Encaixe fora da grade: para atender fora do horário, a profissional abre um horário extra naquele dia.

**Anti-objetivos**
- A agenda não pode virar caminho para alguém ver paciente que não deveria: a nutricionista só tem na agenda pacientes
  **liberados** para ela; a secretária continua sem ver dado clínico.
- Dois pacientes no mesmo horário da mesma profissional — nunca, nem com dois cliques simultâneos.
- Mensagem de aviso com informação clínica (diagnóstico, motivo da consulta): o aviso só tem nome, profissional, data,
  hora e endereço.

## 3. Restrições (herdadas)

PHP 8.1 sem framework, MariaDB, hospedagem cPanel compartilhada; CSP estrita (sem script/estilo inline); tarefas
agendadas só pelo Cron do cPanel (o `cron/run.php` já roda a cada 5 minutos); e-mails pela fila `mail_queue` existente
(`App\Core\Mailer`); fuso `America/Sao_Paulo`; auditoria de toda criação/alteração; exclusão lógica.

## 4. Abordagens consideradas

1. **Horários calculados a partir da semana-modelo** (escolhida): o banco guarda a semana-modelo de cada profissional,
   as exceções (fechamentos e horários extras) e as consultas. Os horários livres de um dia são calculados na hora:
   grade da semana-modelo − fechamentos + extras − consultas ativas. Mudar o horário da profissional vale na hora para
   todas as datas futuras, sem regenerar nada.
2. Gerar no banco uma linha por horário de cada dia (ex.: 12 meses à frente): simples de consultar, mas ocupa espaço,
   precisa de rotina para gerar mais dias e fica inconsistente quando a semana-modelo muda.
3. Agenda livre (só data e hora, sem grade): mais simples, mas perde "próximo horário livre" e o controle de horários
   abertos/fechados que o responsável pediu.

## 5. Como funciona

### 5.1 Horários de cada profissional

- Tela **"Meus horários"** para cada médica e nutricionista: lista de faixas por dia da semana (ex.: seg 07:30–11:30),
  e a **duração da consulta** da profissional (padrão 30 min; vale para toda a agenda dela). Uma profissional pode ter
  várias faixas no mesmo dia; faixas do mesmo dia não podem se sobrepor.
- **Fechamentos**: data inicial e final, com horário opcional (dia inteiro ou um período), e motivo curto (férias,
  congresso). Consultas já marcadas dentro de um fechamento **não são canceladas sozinhas**: a tela avisa "há N
  consultas marcadas nesse período" e a agenda mostra essas consultas destacadas para a equipe remarcar. *(escolha
  minha)*
- **Horário extra**: data + faixa, abre horários naquele dia mesmo fora da semana-modelo.
- Quem edita: a própria profissional; a médica administradora edita a de qualquer profissional; a secretária pode
  **lançar fechamentos** em nome de uma profissional (ex.: a doutora liga avisando), mas não muda a semana-modelo.
  *(escolha minha)*
- Mudança na semana-modelo vale para frente e não mexe em consulta já marcada; uma consulta marcada que fique fora da
  nova grade aparece destacada ("fora do horário atual"), sem ser cancelada.
- A semana-modelo inicial da Dra. Rubia: a Dra. Rubia é a usuária **médica administradora** existente. Na tela "Meus
  horários", se a profissional ainda não tem nenhuma faixa, aparece o botão "Usar horário padrão da clínica" que
  preenche seg–sex 07:30–11:30 e 12:30–20:00, sáb 07:30–12:30, 30 min. *(escolha minha: evita adivinhar na migração qual
  usuário é a Dra. Rubia)*
- Os horários de um dia vão de início a fim da faixa em passos da duração; o último horário começa até `fim − duração`
  (07:30–11:30 com 30 min = 07:30 … 11:00).

### 5.2 Marcar, ver e mudar consultas

- **Agenda do dia** e **agenda da semana** de uma profissional; a secretária escolhe a profissional num seletor; a
  profissional abre direto na dela (e pode ver a das colegas? **não**: profissional vê só a própria agenda; secretária e
  médica administradora veem todas). *(escolha minha)*
- Cada horário mostra: livre ([marcar]), marcado (nome do paciente, idade, tipo, situação, [WhatsApp], [abrir ficha]),
  fechado, ou intervalo.
- **Marcar**: escolher horário livre → buscar paciente (pelas regras de acesso de quem marca) → tipo (**primeira
  consulta** ou **retorno**) → observação administrativa curta opcional (só equipe vê; sem dado clínico) → salvar.
  Também há "marcar no próximo horário livre" a partir da ficha do paciente.
- Só se marca em horário **aberto e livre**, a partir de agora (não no passado).
- **Nutricionista**: só pacientes liberados para ela (`professional_access` ativo). Se a secretária tentar marcar com a
  nutricionista um paciente não liberado, o sistema recusa com "Peça à médica para liberar este paciente para a
  nutricionista." *(escolha minha)*
- **Sem choque de horário**: garantido no banco (índice único por profissional + início entre consultas ativas), não só
  na tela.
- **Mudar situação**: confirmada (um clique, depois que a família respondeu ao aviso), realizada, faltou — só no dia da
  consulta ou depois, exceto "confirmada". **Cancelar**: pede motivo curto; o horário volta a ficar livre.
  **Remarcar**: escolhe novo horário; a antiga fica "remarcada" com ligação para a nova. Consulta realizada/faltou/
  cancelada/remarcada não volta atrás pela tela, exceto desfazer "realizada"/"faltou" no mesmo dia. *(escolha minha)*
- Na ficha do paciente: cartão **"Consultas"** com as próximas e as últimas, para quem tem acesso à agenda daquele
  paciente; para médica, "realizada" mostra o atalho "registrar evolução" (só atalho, sem vínculo obrigatório).

### 5.3 Situação "reservada" (só prevista)

O banco já aceita a situação **reservada** (pedido da família aguardando confirmação, segurando o horário), para não
refazer a estrutura depois. **Nada nesta fase cria uma consulta reservada**; telas e regras de "reservada" ficam para
quando a família puder pedir horário.

### 5.4 Texto do aviso

- Texto padrão (aprovado), editável pela médica administradora numa tela de configurações da agenda:

  > Olá, {nome}! Lembrando da sua consulta com {profissional} amanhã, {dia da semana} {data}, às {hora}. Endereço:
  > {endereço}. Por favor, confirme sua presença respondendo esta mensagem. Até lá!

- Para paciente **menor de 18 anos** na data da consulta, o texto padrão fala com o responsável: "Olá! Lembrando da
  consulta de {nome} com {profissional} …". São dois textos configuráveis (adulto e criança). *(escolha minha)*
- `{nome}` = nome social se houver, senão primeiro nome do paciente; `{profissional}` = nome da profissional como
  cadastrado; `{endereço}` = o endereço já configurado na tela da receita (`rx_address`), sem duplicar cadastro.
- **"amanhã"**: o texto é pensado para ser mandado na véspera. Quando o botão de WhatsApp é usado em outro dia, a
  palavra se ajusta: "hoje", "amanhã" ou omitida (fica só "{dia da semana} {data}"). *(escolha minha)*

### 5.5 Botão de WhatsApp (só equipe)

- Em cada consulta marcada (agendada ou confirmada), um botão por número disponível: o telefone do paciente e o celular
  de cada responsável ativo que tiver celular. Rótulo: "WhatsApp — paciente" / "WhatsApp — {nome do responsável}".
- Ao clicar: copia o aviso para a área de transferência e abre `https://wa.me/<número>?text=<aviso>` em nova aba
  (mensagem já preenchida; se o WhatsApp não preencher, o Ctrl+V funciona). Script em arquivo `.js` externo (CSP).
- Número: só dígitos; com 10 ou 11 dígitos (DDD + número) recebe `55` na frente; com 12–13 dígitos começando por `55`
  fica como está; outro formato → botão não aparece e a consulta mostra "telefone em formato inválido". *(escolha
  minha)*
- O botão não marca nada sozinho como "avisado". *(escolha minha: não há como saber se a mensagem foi enviada)*

### 5.6 E-mails

- **Véspera para a profissional** (*o pedido central*): todo dia, a partir das **08:00**, uma vez por dia, cada
  profissional com consultas ativas (agendada/confirmada) no dia seguinte recebe um e-mail "Avisos das consultas de
  {data}" com, em ordem de horário: hora, nome do paciente, números de WhatsApp e o texto de aviso pronto, em bloco
  fácil de copiar. Sem consultas → sem e-mail. Sábado recebe os avisos de domingo (se houver) e o domingo os de
  segunda — "véspera" é sempre o dia anterior. *(08:00 é escolha minha)*
- **Família**: ao **marcar**, **cancelar** ou **remarcar**, e **lembrete na véspera** (mesma rodada das 08:00), para o
  e-mail do paciente (ficha ou conta) quando for adulto, e para os e-mails dos responsáveis ativos quando for menor.
  Quem não tem e-mail não recebe; nada quebra. O conteúdo do e-mail da família é o mesmo do aviso, sem observações
  internas. *(escolha minha)*
- Tudo vai pela fila `mail_queue` existente; o envio de véspera é feito pelo `cron/run.php` e registra o dia já
  processado (em `settings`) para não enviar duas vezes; se o Cron ficar parado e voltar depois das 08:00, envia uma
  vez naquele dia; dias perdidos não são reenviados. *(escolha minha)*

### 5.7 Celular do responsável

Campo **celular** opcional no cadastro do responsável (tela de responsáveis do paciente), visível só para a equipe com
acesso a `cadastro`. O telefone do paciente continua onde está.

### 5.8 Portal da família

Cartão **"Consultas"** na página inicial do portal: próximas consultas (data, hora, profissional, situação) e as
últimas 5 passadas. Sem botões de ação. Aparece para o paciente e o responsável ativo. *(escolha minha: conta
da criança fica fora)*

### 5.9 Quem vê o quê

| Perfil | Agenda |
|---|---|
| Médica administradora | todas as agendas; edita horários de todas; marca/muda em todas; edita textos do aviso |
| Médica | a própria agenda; edita os próprios horários; marca/muda na própria |
| Nutricionista | a própria agenda, só com pacientes liberados; edita os próprios horários |
| Secretária | todas as agendas; marca/muda em todas; lança fechamentos; não edita semana-modelo |
| Paciente / responsável | só as próprias consultas, no portal |
| Criança | nada |

Uma médica (não administradora) que abre a ficha do paciente vê no cartão "Consultas" as consultas dele **com
qualquer profissional** (data, hora, profissional, situação), porque ela já vê todo o paciente; a nutricionista vê só
as consultas com ela. *(escolha minha)*

## 6. Auditoria

Registrar: criação, mudança de situação, cancelamento, remarcação de consulta (com antes/depois); mudança em semana-
modelo, fechamentos, horários extras e textos do aviso. Abrir a agenda não precisa registrar leitura (não é prontuário);
recusas de acesso seguem o padrão existente ('denied').

## 7. Fica a critério do construtor

Nomes de tabelas/colunas e rotas; como desenhar a grade semanal na tela (seguindo o layout existente); o formato do
e-mail da véspera (HTML simples); como mostrar o seletor de profissional; ordenação e paginação do cartão "Consultas";
tratamento de consultas antigas na migração (não há).

## 8. Escolhas registradas

Todas as marcadas *escolha minha* acima, além de: tipos de consulta só "primeira consulta" e "retorno" com a mesma
duração; agenda de uma profissional desativada some do seletor, mas as consultas ficam no histórico.

## 9. Decisões complementares (verificação do construtor) — *todas escolha minha*

1. **Duração gravada na consulta**: cada consulta guarda início **e fim** (a duração vigente quando foi marcada). A
   profissional pode mudar a duração; consultas já marcadas mantêm a delas. O choque de horário é verificado por
   **sobreposição de intervalos** (não só pelo início), dentro de uma transação que trava a agenda da profissional
   (`SELECT … FOR UPDATE` numa linha por profissional), com o índice único de (profissional, início) entre consultas
   que ocupam horário como segunda barreira. Um horário da grade só é "livre" se não se sobrepõe a nenhuma consulta que
   ocupa horário.
2. **Remarcar para outra profissional é permitido**: todas as regras valem de novo no destino (quem remarca precisa
   poder marcar naquela agenda; nutricionista de destino precisa ter o paciente liberado). A família recebe o e-mail de
   remarcação com o novo nome da profissional.
3. **Situações que ocupam horário**: agendada, confirmada, reservada, realizada, faltou. Liberam o horário: cancelada,
   remarcada. Entram nos avisos e e-mails da véspera só: **agendada e confirmada**. Desfazer "realizada"/"faltou" no
   mesmo dia volta para "agendada".
4. **Médica (não administradora)** pode marcar na própria agenda qualquer paciente que ela já acessa — hoje toda médica
   acessa todos os pacientes (`PatientScope`), então é qualquer paciente. A busca de paciente usa sempre o
   `PatientScope` de quem marca **e**, para agenda de nutricionista, a liberação dessa nutricionista.
5. **Destinatários da família**: adulto → e-mail da ficha; se vazio, o e-mail da conta do paciente. Menor → um e-mail
   **separado para cada responsável ativo** com e-mail. Endereços repetidos são enviados uma vez só.
6. **Consulta em horário fechado ou fora da grade atual**: continua com todas as ações (confirmar, cancelar, remarcar,
   mudar situação); o horário continua ocupado por ela e não pode ser marcado por outra pessoa.
7. **Rodada da véspera**: uma única rodada por dia (a partir das 08:00) gera os e-mails das profissionais **e** os
   lembretes da família, todos colocados na fila numa mesma transação junto com a marca do dia em `settings`; se algo
   falhar antes do fim, nada fica marcado e a próxima passada do Cron refaz a rodada inteira. Depois de na fila, os
   reenvios são da própria fila.
8. **Endereço**: `rx_address` é uma configuração única da clínica (tabela `settings`), usada para todas as
   profissionais.
9. **Tipo** (primeira consulta/retorno) é só um rótulo. Ao marcar, vem sugerido "retorno" se o paciente já tem alguma
   consulta realizada com aquela profissional, senão "primeira consulta"; a equipe pode trocar.
