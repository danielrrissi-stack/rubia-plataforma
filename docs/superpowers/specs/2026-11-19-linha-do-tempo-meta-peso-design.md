# Fase 4d — Linha do tempo e meta de peso

## 1. Intenção

Na **consulta**, a Dra. Rubia abre a ficha e quer ver **tudo o que aconteceu desde a última visita**, numa lista única
por data. Como ela também vai trabalhar com **emagrecimento** (adultos e crianças/adolescentes), entra junto uma
**meta de peso** com gráfico ao longo do tempo: uma linha mostra o caminho combinado até o alvo e, a partir da quinta
medição, outra mostra a tendência real — para responder "estou ou não caminhando para atingir a meta".

Decisões da usuária (2026-11-19): uso na consulta, "desde a última visita"; meta para adultos e crianças; **só a equipe
vê a meta**; forma de controle a meu critério; gráfico com caminho combinado **e** tendência, a tendência só a partir da
5ª medição; as duas partes entregues juntas.

## 2. Objetivos, não-objetivos, anti-objetivos

**Objetivos**
- Página **Linha do tempo** por paciente, com período padrão "desde a última visita".
- **Meta de peso** (uma ativa por paciente) com histórico, cartão na ficha e página com gráfico.
- Situação da meta calculada sozinha a partir das medições: no caminho / à frente / atrás / atingida / prazo vencido.

**Não-objetivos**
- Família, responsável ou criança ver a meta ou a linha do tempo (nem no portal, nem na área da criança).
- Painel de evolução metabólica, exportação LGPD, PDF da linha do tempo (continuam para depois).
- Registros diários na linha do tempo (doses dadas pela família, marcações de metas de hábito, diário) — poluiriam a
  lista *(escolha minha)*.
- Avisos por e-mail ou alerta automático de meta.

**Anti-objetivos**
- A meta de peso aparecer para a família ou para a criança (estigma, especialmente em criança).
- A linha do tempo mostrar a alguém algo que hoje essa pessoa não pode ver na tela de origem.
- Previsão de tendência com poucas medições (por isso o mínimo de 5).

## 3. Abordagens consideradas

- **A) Caminho combinado:** reta do valor de partida até o alvo na data do prazo; situação compara a última medição com
  a reta. Simples, funciona com poucas medições.
- **B) Tendência:** reta de tendência das medições reais e previsão de quando chega ao alvo. Mais informativa, frágil
  com poucas medições.
- **C) As duas** — **escolhida pela usuária**, com a tendência só a partir da 5ª medição. A situação (no caminho etc.)
  vem do caminho combinado (A); a tendência acrescenta a previsão.

## 4. Meta de peso

### 4.1 Tipos *(escolha minha, autorizada pela usuária)*
- **Adulto** (≥ 229 meses na data de criação, mesmo corte das curvas, `GrowthController::ADULT_MONTHS`):
  meta em **peso (kg)**; o IMC aparece junto (alvo e atual), via `AdultAnthropometry::bmi`.
- **Criança/adolescente** (< 229 meses): meta em **escore-z do IMC para a idade** (OMS, o mesmo `GrowthCalculator`
  das curvas). Junto, como referência, o **peso equivalente ao alvo hoje**: o peso que daria o z-alvo na idade e altura
  da última medição (`Lms::valueAt` para o IMC, × altura²). Rótulo: "≈ X kg com a altura atual".
- O tipo é fixado na criação. Em criança, o **prazo não pode passar da data em que o paciente completa 229 meses**
  (depois disso o z deixa de ser calculado); a tela explica e sugere criar uma meta em kg depois.

### 4.2 Dados (migração 014, compatível com MySQL 5.7)
Tabela `weight_goals`: `id`, `patient_id`, `kind ENUM('peso','imc_z')`, `start_on DATE`, `start_value DECIMAL(7,3)`,
`target_value DECIMAL(7,3)`, `target_date DATE`, `notes VARCHAR(500) NULL`, `created_by`, `created_at`,
`ended_at DATETIME NULL`, `ended_by NULL`, `end_reason ENUM('substituida','encerrada') NULL`. Uma meta ativa por
paciente (`ended_at IS NULL`), garantida no banco (coluna gerada STORED + índice único, como `appointments.occupies`) e
no repositório.

### 4.3 Regras
- **Quem define, troca e encerra:** médica e nutricionista com o paciente liberado (seção `ANTROPOMETRIA`, ação
  `edit`). Quem vê: quem tem `ANTROPOMETRIA` `view` **e é equipe** (médica, nutricionista). Secretária não vê. Família
  e criança nunca — a regra fica num ponto só (ex.: `WeightGoalAccess`), não espalhada pelas telas.
- **Valor de partida:** a medição mais recente válida (não apagada nem substituída) até hoje. Adulto: com peso.
  Criança: com peso e altura no mesmo registro. Sem ela, a tela pede para registrar uma medição antes. `start_on` e
  `start_value` são gravados (foto do momento).
- **Validação:** prazo > hoje e ≤ 5 anos; alvo diferente da partida; adulto 20–300 kg; criança z entre −3 e +5.
  Direção (perder ou ganhar) sai de alvo vs. partida — a meta serve também para ganho.
- **Trocar a meta** encerra a atual (`substituida`) e cria a nova na mesma operação. **Encerrar** sem nova:
  `encerrada`. Nada é apagado. Tudo vai para a auditoria.
- **Medições que contam:** válidas, de `start_on` em diante, equipe ou família (a da família aparece marcada
  "família"). Duas no mesmo dia: vale a mais recente registrada.

### 4.4 Situação (calculada, nunca gravada)
- **Caminho combinado:** reta de (`start_on`, `start_value`) a (`target_date`, `target_value`).
- Com a última medição que conta (data `d`, valor `v`) e o esperado `e(d)` na reta:
  - **Atingida:** `v` chegou ao alvo (≤ alvo se perder, ≥ alvo se ganhar). A primeira medição que atinge vira o evento
    "meta atingida". A meta continua ativa até a equipe encerrar ou trocar.
  - **Prazo vencido:** hoje > prazo e não atingida.
  - **No caminho:** |v − e(d)| ≤ tolerância — **1,0 kg** (adulto) ou **0,2 de z** (criança) *(escolha minha)*.
  - **À frente do caminho** / **Atrás do caminho:** fora da tolerância, a favor ou contra a direção da meta.
  - Sem medição depois da partida: "aguardando a primeira medição".
- **Tendência (a partir da 5ª medição que conta, contando a de partida):** reta por mínimos quadrados (valor × dias)
  sobre as medições que contam. Frase:
  - "Nesse ritmo, chega ao alvo em mm/aaaa" (antes ou depois do prazo — dizer qual);
  - "Nesse ritmo, não se aproxima do alvo" (inclinação zero ou contra a direção);
  - previsão além de 5 anos: "nesse ritmo, levaria mais de 5 anos".
  Com menos de 5: "a tendência aparece a partir da 5ª medição (faltam N)".

### 4.5 Telas
- **Cartão "Meta de peso" na ficha** (só equipe que vê): alvo e prazo, atual, situação com selo, frase da tendência,
  botão "Ver meta". Sem meta: "Nenhuma meta de peso" + "Definir meta" (se pode editar).
- **Página `/pacientes/{id}/meta-peso`:** gráfico (Chart.js local + `.js` externo, sem script inline — CSP), meta
  atual, formulário de definir/trocar/encerrar, histórico das metas anteriores (com situação final).
  - Gráfico: eixo y em kg (adulto) ou z do IMC (criança); pontos das medições; **caminho combinado** tracejado até
    a estrela do alvo; **tendência** em outra cor, só a partir da 5ª medição, estendida até o prazo; linha horizontal
    do alvo. Legenda e texto alternativo com a situação (não depender só de cor).
  - Dados por um endpoint JSON (padrão de `curvas.json`), com a mesma checagem de acesso.
  - Abaixo do gráfico, tabela das medições que contam (data, valor, IMC ou z, diferença para o esperado).

## 5. Linha do tempo

### 5.1 Onde
- Página `/pacientes/{id}/linha-do-tempo`, para **médica e nutricionista** (secretária não). Botão "Linha do tempo" no
  topo da ficha. No topo da página, o cartão da meta de peso (se houver e puder ver).

### 5.2 Período
- Padrão **"desde a última visita"**: a última consulta da agenda com situação `realizada` e início antes de agora
  (qualquer profissional). Sem ela: data da última evolução. Sem nenhuma das duas: "tudo".
- Opções: desde a última visita · 3 meses · 12 meses · tudo (parâmetro na URL). O cabeçalho diz a data de referência
  e quantos dias se passaram.
- A própria consulta da última visita aparece na lista (no fim).

### 5.3 Eventos e quem vê cada um
Cada tipo só entra se a pessoa pode ver a seção de origem hoje (`Gate` + regras do módulo):

| Tipo | Fonte | Texto | Seção |
|---|---|---|---|
| Consulta | `appointments` realizada ou faltou | "Retorno com {profissional} — realizada/faltou" | AGENDA, mesma regra do cartão Consultas (nutricionista: só as dela) |
| Evolução | `clinical_entries` kind evolucao, vigente | "Evolução registrada" + diagnóstico curto, se houver | CLINICO (médica) |
| Exame | `lab_collections` não apagada | "Coleta: N resultados, M alterados" + até 3 alterados | EXAMES (médica) |
| Remédio | `prescriptions` início/fim; `prescription_doses` troca | "Início: X dose"; "X: dose A → B"; "X suspenso" | MEDICAMENTOS (médica) |
| Evento adverso | `adverse_events` | "Evento adverso: … (gravidade)" | MEDICAMENTOS (médica) |
| Medição | `measurements` válidas | Peso, IMC (ou z do IMC na criança), PA, diferença de peso para a anterior; selo "família" | ANTROPOMETRIA |
| Plano alimentar | `meal_plans` publicado | "Plano publicado: {título}" | NUTRICAO |
| Meta de hábito | `patient_goals` criada/encerrada | "Nova meta: …" / "Meta encerrada: …" | METAS (médica) |
| Meta de peso | `weight_goals` | definida / trocada / encerrada / atingida | regra da meta de peso |

- Ordem: mais recente em cima; no mesmo dia, ordem fixa por tipo (consulta por último). Rótulo do tipo em cada linha
  (texto, não só cor). Cada linha tem link para a tela de origem.
- Sem eventos: "Nada registrado neste período."
- Abrir a linha do tempo registra auditoria (visualização), como as outras telas clínicas.
- Volume: período "tudo" pode ser grande — paginar ou limitar (ex.: 200 eventos com "ver mais") *(fica com o
  construtor)*.

## 6. Restrições
- PHP 8.1, MVC próprio, sem Composer; MySQL 5.7 (Percona) — migração sem sintaxe só do MariaDB
  (`MigrationPortabilityTest`).
- CSP: sem script ou estilo inline; JS em arquivo externo; Chart.js já versionado em `public/assets/vendor`.
- Instalação: migração 014; arquivos novos em `public/assets/js` → precisa copiar `public/` desta vez.
- Fuso America/Sao_Paulo; datas dd/mm/aaaa; números com vírgula.

## 7. Fica com o construtor
- Nomes de classes, rotas exatas além das citadas, arranjo da página, paginação da linha do tempo.
- Texto exato dos selos e mensagens, mantendo o sentido acima.
- Como reaproveitar o cálculo de z (`GrowthCalculator`) sem duplicar.
