# Exames laboratoriais: fontes das faixas pré-carregadas

Seed: `database/seeds/lab_catalog.sql` (ids fixos 1..99; cadastros próprios começam em 1000). Recarregar o seed é
no-op (`INSERT ... ON DUPLICATE KEY UPDATE id = id`), e as faixas de um exame só são inseridas enquanto ele não foi
revisado (`reference_confirmed = 0`), para que recarregar não recrie faixa apagada pela médica. Todas as faixas
pré-carregadas aparecem com o selo **"a confirmar"** até a médica salvar ou confirmar o exame no catálogo.

Pesquisa feita em 2026-11-17.

## Fontes

1. **NHLBI 2011** — Expert Panel on Integrated Guidelines for Cardiovascular Health and Risk Reduction in Children
   and Adolescents. *Summary Report.* Pediatrics 2011;128(Suppl 5):S213–S256. Tabela "Acceptable, Borderline-High,
   and High Plasma Lipid, Lipoprotein, and Apolipoprotein Concentrations (mg/dL) for Children and Adolescents".
   Conferido na reprodução da tabela em: *NHLBI Expert Panel Releases Guidelines for Cardiovascular Health and Risk
   Reduction in Children*, Am Fam Physician 2012;86(8):776–781, Table 2 —
   https://www.aafp.org/pubs/afp/issues/2012/1015/p776.html (relatório completo:
   https://www.nhlbi.nih.gov/health-topics/integrated-guidelines-for-cardiovascular-health-and-risk-reduction-in-children-and-adolescents).
   Valores (mg/dL): CT aceitável < 170, limítrofe 170–199, alto ≥ 200; LDL < 110 / 110–129 / ≥ 130;
   não-HDL < 120 / 120–144 / ≥ 145; TG 0–9 anos < 75 / 75–99 / ≥ 100; TG 10–19 anos < 90 / 90–129 / ≥ 130;
   HDL aceitável > 45, limítrofe 40–45, baixo < 40.
2. **SBC 2017** — Faludi AA et al. *Atualização da Diretriz Brasileira de Dislipidemias e Prevenção da
   Aterosclerose – 2017.* Arq Bras Cardiol 2017;109(2 Supl 1):1–76. doi:10.5935/abc.20170121 —
   https://www.scielo.br/j/abc/a/whBsCyzTDzGYJcsBY7YVkWn/ (Tabela 2: valores referenciais e de alvo terapêutico,
   adultos > 20 anos). As tabelas do artigo estão em imagem; os números foram conferidos na fonte abaixo, que é a
   mesma tabela (a diretriz a cita como origem).
3. **Consenso 2016** — SBAC, SBD, SBC/Departamento de Aterosclerose, SBEM, SBPC/ML. *Consenso Brasileiro para a
   Normatização da Determinação Laboratorial do Perfil Lipídico*, versão 1.13 (2016) —
   https://www.sbac.org.br/wp-content/uploads/2017/09/ConsensoOficial_PerfilLipidico_2016_v13.pdf
   - Tabela I (adultos > 20 anos), com jejum / sem jejum: CT < 190 / < 190 desejável; HDL-C > 40 / > 40 desejável;
     TG < 150 / < 175 desejável; LDL-C e não-HDL-C **por categoria de risco** (LDL < 130 baixo, < 100 intermediário,
     < 70 alto, < 50 muito alto; não-HDL < 160, < 130, < 100, < 80).
   - Tabela II (crianças e adolescentes), com jejum / sem jejum: CT < 170; HDL-C > 45; TG 0–9 a < 75 / < 85;
     TG 10–19 a < 90 / < 100; LDL-C < 110. Só valores desejáveis (sem limítrofe/alto) — os cortes limítrofe/alto vêm
     do NHLBI 2011, cujos valores desejáveis coincidem.
4. **ADA 2025** — American Diabetes Association Professional Practice Committee. *2. Diagnosis and Classification of
   Diabetes: Standards of Care in Diabetes—2025.* Diabetes Care 2025;48(Suppl 1):S27–S49 (tabela de critérios de
   pré-diabetes e diabetes): glicemia de jejum 100–125 mg/dL = pré-diabetes, ≥ 126 = diabetes; HbA1c 5,7–6,4 % =
   pré-diabetes, ≥ 6,5 % = diabetes. Os mesmos critérios valem para crianças e adolescentes (capítulo 14, *Children
   and Adolescents*, Diabetes Care 2025;48(Suppl 1):S283–S305). Os cortes foram conferidos na tabela de critérios da
   edição resumida de 2023 (Standards of Care 2023, tabela 2.2/2.5, https://pmc.ncbi.nlm.nih.gov/articles/PMC9845083),
   que não mudaram desde então; *conferir na fonte as páginas da edição 2025* (texto integral não aberto) —
   https://diabetesjournals.org/care/issue/48/Supplement_1. **SBD** — Diretriz da Sociedade Brasileira de Diabetes
   (edição vigente, https://diretriz.diabetes.org.br/) adota os mesmos cortes; *conferir na fonte a edição citada*
   (a página da SBD não foi aberta nesta pesquisa).
5. **NASPGHAN 2017** — Vos MB, Abrams SH, Barlow SE et al. *NASPGHAN Clinical Practice Guideline for the Diagnosis
   and Treatment of Nonalcoholic Fatty Liver Disease in Children.* J Pediatr Gastroenterol Nutr 2017;64(2):319–334.
   Recomendação: interpretar a ALT pelo limite superior biológico por sexo — **22 U/L meninas, 26 U/L meninos**
   (estudo SAFETY, Schwimmer JB et al., Gastroenterology 2010;138:1357–1364) — e não pelo limite de cada
   laboratório. Conferido em resumos do guideline (busca em 2026-11-17; o texto integral no PMC,
   https://pmc.ncbi.nlm.nih.gov/articles/PMC5413933/, pediu verificação de robô e não abriu).

## Conversão dos cortes publicados (§5.3 do spec)

O corte gravado **pertence à categoria pior** (v ≥ alterado_alto → alterado; v ≤ alterado_baixo → alterado).
- Lado alto, "≥ 130 alto" → `abnormal_high = 130`; "limítrofe 110–129" → `borderline_high = 110`.
- Lado baixo publicado como "< 40" → maior valor ainda alterado na precisão do exame (0 casas) → `abnormal_low = 39`;
  "limítrofe 40–45" → `borderline_low = 45`.
- Lado baixo publicado como "desejável > 40" (adultos, Consenso Tabela I): 40 já não é desejável → `abnormal_low = 40`.
- Valor só "desejável < X" sem faixa limítrofe (adultos): `abnormal_high = X`, sem limítrofe.
- ALT "limite superior normal 26" (valor inteiro) → alterado a partir de 27 (`abnormal_high = 27`); F 22 → 23.
- "0–9 anos" = 24–119 meses (o rastreamento começa aos 2 anos); "10–19 anos" = 120–239 meses; adulto = 240 meses
  em diante, sem limite superior.

## Faixas que entraram no seed

| id | Exame | Sexo | Idade (meses) | Limítrofe alto | Alterado alto | Limítrofe baixo | Alterado baixo | Fonte (coluna `source`) |
|---|---|---|---|---|---|---|---|---|
| 1 | Colesterol total | ambos | 24–239 | 170 | 200 | — | — | NHLBI 2011 / SBC 2017 |
| 2 | LDL-colesterol | ambos | 24–239 | 110 | 130 | — | — | NHLBI 2011 / SBC 2017 |
| 3 | Colesterol não-HDL | ambos | 24–239 | 120 | 145 | — | — | NHLBI 2011 |
| 4 | HDL-colesterol | ambos | 24–239 | — | — | 45 | 39 | NHLBI 2011 / SBC 2017 |
| 5 | Triglicerídeos | ambos | 24–119 | 75 | 100 | — | — | NHLBI 2011 / SBC 2017 |
| 6 | Triglicerídeos | ambos | 120–239 | 90 | 130 | — | — | NHLBI 2011 / SBC 2017 |
| 7 | Colesterol total | ambos | ≥ 240 | — | 190 | — | — | SBC 2017 / Consenso 2016 |
| 8 | HDL-colesterol | ambos | ≥ 240 | — | — | — | 40 | SBC 2017 / Consenso 2016 |
| 9 | Triglicerídeos | ambos | ≥ 240 | — | 150 | — | — | SBC 2017 / Consenso 2016 |
| 10 | Glicemia de jejum | ambos | ≥ 24 | 100 | 126 | — | — | ADA 2025 / SBD |
| 11 | HbA1c | ambos | ≥ 24 | 5,7 | 6,5 | — | — | ADA 2025 / SBD |
| 12 | TGP (ALT) | M | 24–239 | — | 27 | — | — | NASPGHAN 2017 (SAFETY) |
| 13 | TGP (ALT) | F | 24–239 | — | 23 | — | — | NASPGHAN 2017 (SAFETY) |

Triglicerídeos usam os valores **em jejum** (o Consenso também traz valores sem jejum: crianças < 85 / < 100,
adultos < 175; a médica pode ajustar se o laboratório colher sem jejum).

## Exames e idades sem faixa (e por quê)

- **Insulina de jejum** (e o HOMA-IR calculado): não há valor de referência pediátrico consensual; os cortes
  publicados variam com idade, puberdade, sexo, etnia e método de dosagem. Sem faixa — a médica define.
- **TGO (AST)**, todas as idades: as diretrizes consultadas não dão um limite único (NASPGHAN usa só a ALT); o
  valor depende do método e do laboratório. Sem faixa.
- **TGP (ALT) adulto** (≥ 240 meses): o limite usado é o de cada laboratório/método; sem valor único nas fontes
  consultadas. Sem faixa.
- **LDL-colesterol e colesterol não-HDL adultos**: a SBC 2017 / Consenso 2016 definem metas **por categoria de risco
  cardiovascular** (baixo, intermediário, alto, muito alto), não um valor referencial único. Sem faixa.
- **Menores de 24 meses**: as tabelas usadas começam o rastreamento aos 2 anos. Sem faixa.

## Diferenças em relação aos valores de partida do plano

- **HDL adulto**: o plano sugeria alterado ≤ 39 ("< 40"). A Tabela I do Consenso 2016 (reproduzida na SBC 2017)
  publica **"desejável > 40"**, então 40 já não é desejável → gravado `abnormal_low = 40`. (No texto, a SBC 2017
  ainda define "HDL-c baixo" para classificar dislipidemia como < 40 em homens e < 50 em mulheres; optou-se pelo
  valor referencial da tabela do laudo, igual para os dois sexos. A médica pode ajustar.)
- Demais números conferem com as fontes.
