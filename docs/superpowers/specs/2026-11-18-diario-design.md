# Diário de hábitos (Fase 3c) — design

Módulo 6 da especificação geral (`2026-11-01-plataforma-rubia-design.md`). Em 2026-11-18 o responsável pelo projeto
escolheu "o Diário mostra só o que a equipe pediu para aquele paciente" e aprovou as demais propostas ("siga direto com
as propostas acima"). Itens marcados *escolha minha* foram decididos por mim.

## 1. Intenção e porquê

Na consulta, a médica quer ver **como foi a semana** (o que a criança comeu, quanto dormiu, quanto tempo de tela) sem
depender da memória da família. Cada paciente precisa de coisas diferentes: uma família só precisa pesar às segundas,
outra precisa anotar a alimentação e o sono. Por isso **a equipe pede, por paciente, o que quer que seja registrado**, e
a família vê no portal só esses pedidos. Assim a tela da família fica curta e não repete o que já é marcado nas Metas
(fase 3a). Sucesso: a família registra o dia em poucos toques; a médica abre a aba "Diário" do paciente e vê a semana em
grade, com as refeições ao lado do plano alimentar, e deixa comentários que a família lê.

## 2. Objetivos, não-objetivos, anti-objetivos

**Objetivos**
- **Pedidos de registro** por paciente, criados pela médica ou pela nutricionista: tipo, dias da semana, início, fim
  opcional e uma orientação curta para a família.
- Tipos: **alimentação** (refeição, horário, o que comeu, foto opcional), **água** (copos), **sono** (dormiu, acordou,
  como dormiu), **atividade/brincadeira** (o que fez, minutos), **tempo de tela** (minutos), **peso e medidas** (leva à
  tela de medidas que já existe).
- Família registra pelo portal o dia de hoje e até 7 dias para trás; corrige e apaga os próprios registros.
- Equipe vê a semana em grade (pedido × dia), as refeições ao lado do plano alimentar daquele dia e comenta cada
  registro. O comentário aparece para a família.
- Pedido **sigiloso** para menor de 18 anos (só a médica marca), com a mesma regra dos medicamentos.

**Não-objetivos (agora)**
- Lembretes ou avisos para a família (e-mail, notificação).
- Avisos novos na tela inicial da médica.
- Conta própria da criança (`crianca`) no diário: continua só nas metas.
- Ligação automática entre diário e metas (registrar sono não marca a meta de sono).
- Campos configuráveis pela equipe além dos tipos acima.

**Anti-objetivos**
- **Julgar a alimentação**: nenhuma nota, cálculo de calorias/nutrientes, cor de "certo/errado" ou comparação
  automática com o plano. A comparação é visual, lado a lado, para conversar na consulta.
- Família preenchendo duas vezes a mesma coisa (diário e metas). A equipe decide o que pede.
- Pedido sigiloso deixando rastro para o responsável (cartão, contagem, registros, comentários).
- Nutricionista vendo registros de sono, atividade ou tela, ou qualquer pedido sigiloso.
- Foto acessível por endereço público.
- Apagar de verdade um registro. Toda exclusão é lógica e auditada.

## 3. Restrições (herdadas)

As mesmas das fases anteriores: cPanel compartilhado, PHP 8.1+ puro, sem Composer/Node em produção e nenhuma biblioteca
nova; CSP estrita (sem script/estilo inline); migração numerada (próxima: `012_diary.sql`); auditoria de leitura e
escrita; fuso America/Sao_Paulo. Arquivos enviados ficam fora da pasta pública, como os laudos (`App\Labs\AttachmentStore`,
limite 10 MB). A extensão GD **não está disponível no ambiente de desenvolvimento** e pode não estar na hospedagem.

## 4. Abordagens consideradas

- **Pedidos + registros (escolhida).** Uma tabela de pedidos (o que a equipe pediu, para quais dias) e uma de registros
  (o que a família preencheu, com os valores do tipo), mais comentários. Mesmo padrão dos medicamentos (prescrição +
  registros da família).
- **Reaproveitar as metas**, com um tipo "registro". Mistura dois conceitos (marcar que cumpriu × anotar quanto/como) e
  complica a área da criança, que mostra as metas. Descartada.
- **Construtor de formulários genérico** (a equipe monta os campos). Flexível, mas grande demais e difícil de resumir
  numa grade semanal. Descartada.
- **Diário completo para todos** e **hábitos só nas metas** foram as alternativas de produto; o responsável escolheu
  "só o que a equipe pediu".

## 5. Como funciona

### 5.1 Pedido de registro
- Campos: **tipo** (`alimentacao`, `agua`, `sono`, `atividade`, `tela`, `medidas`); **dias da semana** (um ou mais;
  atalho "todo dia"); **início** (padrão hoje); **fim** opcional (≥ início); **orientação para a família** (opcional,
  até 255 caracteres, ex.: "anote tudo, inclusive beliscos"); **sigiloso** (só médica, só paciente menor de 18, mesma
  regra R6 dos medicamentos: nunca é desligado implicitamente; checkbox mostrado se a médica pode marcar ou se já é
  sigiloso, com campo oculto `secret_shown`).
- Vários pedidos do mesmo tipo podem existir para o mesmo paciente, mas não com períodos e dias sobrepostos *(escolha
  minha: evita dois "água" no mesmo dia)*.
- Ações: criar, editar, **encerrar** (fim = hoje), apagar (lógico, só se não houver registros; com registros, só
  encerrar).
- Com registros, o **tipo fica travado**; dias, período e orientação continuam editáveis. Registros feitos antes em dias
  que deixaram de valer não somem: continuam na lista dia a dia, e a célula da grade mostra o valor mesmo sem pedido
  naquele dia *(escolha minha)*.
- Marcar como sigiloso vale **retroativamente** para tudo do pedido (registros, fotos, comentários, grade, cartão e
  contagem): o responsável deixa de ver e de editar, inclusive o que ele mesmo registrou *(escolha minha)*.
- Nutricionista cria/edita/encerra só pedidos `alimentacao` e `agua`. Só a médica cria os demais.
- Um pedido vale num dia D quando D está entre início e fim e o dia da semana de D está marcado.

### 5.2 Registros da família
- **alimentacao**: refeição (café da manhã, lanche da manhã, almoço, lanche da tarde, jantar, ceia, outro), horário
  (opcional), o que comeu (texto obrigatório, até 1000), foto opcional. **Vários por dia.**
- **agua**: copos (0 a 30). **Um por dia** (salvar de novo substitui).
- **sono**: dormiu às (HH:MM, da noite anterior), acordou às (HH:MM), como dormiu (1 = mal, 2 = mais ou menos,
  3 = bem, mostrado com carinhas). Um por dia (o dia é o do acordar).
- **atividade**: o que fez (até 120), minutos (1 a 600). Vários por dia.
- **tela**: minutos (0 a 1440). Um por dia.
- **medidas**: não tem registro próprio. O portal mostra "Pesar hoje" com link para `/portal/p/{id}/medidas/nova`; a
  grade considera o dia preenchido se houver medida com `source = 'familia'` naquela data.
- Só dá para registrar em dia em que o pedido vale, entre hoje − 7 dias e hoje. Datas futuras, não.
- Pode registrar qualquer responsável vinculado ao paciente ou o próprio paciente, como nas doses de medicamento.
- Tipos de **vários por dia** (alimentação, atividade): quem registrou corrige ou apaga o próprio registro dentro da
  mesma janela de 7 dias; os demais membros da família veem.
- Tipos de **um por dia** (água, sono, tela): o registro é do dia (único por pedido e dia). Qualquer membro da família
  que o vê pode corrigir ou apagar; o registro guarda quem salvou por último, e os comentários continuam ligados a ele
  *(escolha minha)*.
- Cada registro guarda quem registrou e o papel (`responsavel` ou `paciente`).

### 5.3 Fotos
- JPEG, PNG ou WebP, até 10 MB (o menor entre isso e o `upload_max_filesize`), validadas pelo conteúdo, não pela
  extensão.
- Se a GD estiver disponível, a foto é reduzida para no máximo 1280 px no maior lado e regravada em JPEG (isso também
  remove os metadados, como localização). Sem GD, guarda o original *(escolha minha)*; o README avisa que a GD reduz o
  espaço usado.
- Guardadas fora da pasta pública; servidas por rota autenticada que aplica a mesma regra de visibilidade do registro.
- Texto de ajuda no formulário: "Fotografe só o prato".
- Registro apagado (lógico) mantém o arquivo no disco, mas a rota deixa de servir a foto *(escolha minha)*.

### 5.4 Quem vê o quê
| Papel | Equipe (`/pacientes/{id}/diario`...) | Portal |
|---|---|---|
| médico | tudo, inclusive sigilosos; comenta tudo | — |
| nutricionista (só pacientes liberados) | pedidos e registros de `alimentacao` e `agua` não sigilosos; comenta esses | — |
| secretária | nada (403) | — |
| responsável | — | pedidos não sigilosos vigentes, registros (de qualquer membro da família) e comentários desses pedidos |
| paciente (próprio login) | — | tudo do próprio diário, inclusive sigiloso |
| criança (login próprio) | — | nada (guarda `childarea` já prende nas metas) |

Nova seção `Policy::DIARIO`: médico vê e edita; paciente e responsável veem; a nutricionista entra pelo acesso de
`NUTRICAO` ao paciente liberado, como nas metas (o registro é regra do
repositório, como as marcações de metas). A restrição por tipo para a nutricionista e o sigilo são regras do
repositório, num único ponto (como `PrescriptionRepository::visibleTo`). As telas da equipe exigem papel `medico` ou
`nutricionista` **antes** do Gate, porque a família ganha `view DIARIO` para o portal.

### 5.5 Telas da equipe
- **Ficha do paciente:** cartão "Diário" com os pedidos vigentes (tipos) e a contagem dos últimos 7 dias
  ("5 de 7 dias"): o denominador são os dias com algum pedido que vale; um dia conta como preenchido quando **todos**
  os pedidos daquele dia têm registro. Cada profissional vê a contagem só com os pedidos que ela enxerga
  *(escolha minha)*.
- **Diário do paciente** (`/pacientes/{id}/diario`): semana de segunda a domingo, com "semana anterior/próxima"
  (padrão: semana atual). Grade: uma linha por pedido vigente na semana, uma coluna por dia. Célula: o valor resumido
  ("6 copos", "21:30–07:00 😊", "1h20 de tela", "3 refeições", "pesou") quando há registro; "—" quando foi pedido e não
  há registro; vazia quando não foi pedido naquele dia.
- Abaixo da grade, dia a dia (só dias com registros): os registros completos, cada um com "Comentar". Nos dias com
  alimentação, as refeições ficam **ao lado do plano alimentar vigente naquele dia**: plano estruturado → lista de
  refeições do plano (nome, horário, alimentos); plano em texto → o texto do plano; sem plano → nada.
- Plano vigente no dia D: o plano do paciente publicado até o fim de D e não substituído/encerrado antes de D.
- **Pedidos:** lista dos vigentes e futuros, encerrados recolhidos; "Novo pedido", editar, encerrar.
- Em telas pequenas a grade rola dentro do próprio quadro, sem rolar a página.

### 5.6 Comentários
- Médica e nutricionista (dentro do que veem) comentam um registro. Texto até 500. Vários por registro.
- A autora edita ou apaga (lógico) o próprio comentário.
- A família vê o comentário embaixo do registro, com o nome da profissional e a data. Não há resposta da família
  *(escolha minha: conversa fica para o Módulo 9, Mensagens)*.

### 5.7 Portal da família
- Cartão **"Diário"** no início, só quando há pedido visível vigente hoje ou nos 7 dias anteriores.
- Página **Diário** (`/portal/p/{id}/diario`): escolha do dia (hoje e os 7 anteriores, padrão hoje). Para o dia: cada
  pedido que vale naquele dia, com a orientação da equipe, o que já foi registrado (com comentários) e o botão para
  registrar ou corrigir.
- Linguagem simples: "O que a Dra. Rubia pediu para anotar", "Anotar refeição", "Como dormiu?".

## 6. Auditoria
- `create`/`update`/`end`/`delete` em `diary_requests`; `create`/`update`/`delete` em `diary_entries`;
  `create`/`update`/`delete` em `diary_comments`.
- Leitura das telas da equipe: `('view', 'diary', null, patientId, null, ['section' => 'diario'])`; leitura do
  portal igual, como nas demais páginas do portal. Download de foto não é auditado individualmente *(escolha minha:
  a página que a mostra já é)*.

## 7. Fica a critério do construtor
- Nomes de tabelas e classes (sugestão: `app/Diary/`, `diary_requests`, `diary_entries`, `diary_comments`), como guardar
  os valores de cada tipo (colunas próprias ou JSON validado), layout das telas seguindo o padrão de metas e
  medicamentos.
- Como representar os dias da semana (máscara de bits ou conjunto).
- Os testes não podem depender da GD.

## 8. Escolhas registradas
1. O Diário mostra só os pedidos da equipe (escolha do responsável).
2. Peso e medidas não ganham registro novo: o pedido leva à tela de medidas existente.
3. Janela de 7 dias para registrar, corrigir e apagar, como as doses de medicamento.
4. Sigilo igual ao dos medicamentos (menor de 18, só a médica, regra R6). Nutricionista não vê pedidos sigilosos.
5. Nutricionista: só alimentação e água, seguindo a tabela de acessos da especificação geral.
6. Sem avisos na tela inicial e sem ligação automática com as metas.
7. Fotos reduzidas quando há GD; sem GD, guarda o original até 10 MB.
8. Comentário é só da equipe; resposta da família fica para Mensagens.
