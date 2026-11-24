# Painel de evolução — design

## Intenção
A médica quer abrir uma página do paciente e responder: **"a medicação atual ainda está fazendo efeito no
emagrecimento, ou parou?"**. Exemplo dela: tirzepatida com a dose subindo ao longo das semanas, combinada com
outros remédios. O painel **cruza a receita (remédios e mudanças de dose) com a curva de peso** e, recolhido
embaixo, mostra se o metabolismo acompanha (cintura, pressão, exames).

Contexto da prática (dito pela usuária): a família pesa em casa **toda semana** (portal, "Pesar hoje") e às
vezes há pesagem no consultório.

## Objetivos
1. Página nova `/pacientes/{id}/evolucao` ("Evolução").
2. **Curva de peso** ao longo do tempo. Adulto: kg. Paciente com menos de 229 meses (fim das curvas da OMS,
   mesmo limite de `WeightGoalKind::ADULT_MONTHS`): **escore-z do IMC** (decisão da usuária: "para criança usar
   escore-z"). Pesagem de casa e do consultório com marcas diferentes (casa: bolinha vazia; consultório: cheia).
   Se houver meta de peso ativa do mesmo tipo, a linha do caminho esperado aparece junto *(escolha minha)*.
3. **Faixas de remédio** abaixo da curva, no mesmo eixo de datas: uma linha por prescrição, cada trecho é uma
   dose (`prescription_doses.effective_date` até a próxima dose ou `end_date`), com o texto da dose
   ("2,5 mg semanal"). Remédio sigiloso segue a regra que já existe (a médica vê).
4. **Ritmo por dose**: em cada trecho de dose, o ritmo de perda (kg/semana, ou escore-z por 4 semanas na
   criança), pela reta de mínimos quadrados dos pontos do trecho. Trecho com menos de 2 pontos ou menos de
   7 dias: "poucos dados".
5. **Aviso "estabilizou"**: olha as **últimas 4 semanas** (28 dias até a última pesagem). Se alguma dose de
   remédio ativo mudou dentro dessa janela, a janela começa na mudança. Precisa de pelo menos 3 pontos
   cobrindo pelo menos 14 dias; senão mostra "aguardando dados (nova dose há N dias)". Critério por paciente:
   - adulto, modo **kg/semana**: perda menor que **0,2 kg/semana** (padrão) → "Perda de peso estabilizou";
   - adulto, modo **% em 4 semanas**: perda menor que **1% do peso do início da janela em 4 semanas** (padrão);
   - criança (< 229 meses): queda do escore-z menor que **0,05 em 4 semanas** (padrão).
   Ganhar peso também conta como estabilizou. Valores pela reta (não pela diferença entre 2 pontos), para uma
   pesagem fora da curva não disparar o aviso *(escolha minha)*.
6. **Critério editável por paciente**: na própria página, a médica escolhe o modo do adulto (kg/semana ou %)
   e os valores (kg, %, escore-z). Sem configuração salva, valem os padrões acima.
7. **Metabolismo** (recolhido, `<details>`), mesmo eixo de datas, uma curva pequena por série, só as que
   têm algum valor: cintura (cm), pressão arterial (máxima/mínima, com a cor da classificação que já existe),
   glicemia de jejum, HbA1c, insulina, triglicerídeos, HDL, TGP (códigos do catálogo: `glicemia`, `hba1c`,
   `insulina`, `triglicerides`, `hdl`, `tgp`). Pontos de exame com a cor normal/limítrofe/alterado de hoje.
8. **Período**: 3 meses, 6 meses, 12 meses, tudo; padrão **6 meses** *(escolha minha)*. O cálculo do aviso usa
   sempre as últimas 4 semanas, qualquer que seja o período mostrado.
9. Botão **"Evolução"** no cartão Tratamento e no cartão Meta de peso da página do paciente, e na Linha do
   tempo, para quem pode ver *(escolha minha)*.

## Não-objetivos
- PDF ou impressão do painel (pode vir depois).
- Família, responsável, criança, secretária ou nutricionista verem o painel.
- Ler a dose como número (o texto da dose é livre; a faixa mostra o texto).
- Alerta automático, e-mail ou mensagem quando estabilizar.
- Usar as aplicações registradas pela família (adesão) no cálculo; ficam para depois.

## Anti-objetivos
- Aviso de "estabilizou" disparado por uma única pesagem estranha ou por janela curta demais logo depois de
  trocar a dose.
- Curva de criança em kg sugerindo que o remédio falhou quando ela só está crescendo.

## Restrições
- PHP 8.1 MVC existente, MySQL 5.7 na hospedagem (nada só de MariaDB), CSP `default-src 'self'` (sem script
  inline), Chart.js já vendorizado (`public/assets/vendor/chart.umd.min.js`), CSS/JS via `asset()`.
- **Acesso**: só papel `medico`, com `Gate` liberando `view` em `ANTROPOMETRIA`, `MEDICAMENTOS` e `EXAMES`
  para o paciente (a nutricionista não vê remédios nem exames pela matriz atual). Editar o critério: `edit` em
  `ANTROPOMETRIA` e `MEDICAMENTOS`. Visualização registrada na auditoria (`section => 'evolucao'`).
- Migração nova `015_evolution_settings.sql`: uma linha por paciente (modo do adulto `kg|pct`,
  `kg_per_week`, `pct_per_4w`, `z_per_4w`, quem e quando alterou). Sem linha = padrões.
- Medidas: só as vigentes (`MeasurementRepository::current`, sem substituídas/apagadas); um ponto por dia como
  em `WeightGoalPoints::build`.

## Abordagens consideradas
- **A) Página nova "Evolução", cálculo no servidor e gráficos com Chart.js — escolhida pela usuária.**
  Responde à pergunta dela, funciona com ou sem meta de peso, reaproveita o que existe; regras testáveis.
- B) Dentro da tela Meta de peso: mistura duas perguntas numa tela cheia e não serve para quem toma remédio
  sem meta.
- C) Página nova com gráficos desenhados como imagem no servidor: imprime igual, mas dá muito mais trabalho e
  perde o passar o mouse.

## Decidido pela usuária
- Pergunta do painel: a medicação atual ainda funciona no emagrecimento; cruzar receita com curva de peso.
- Opção B do conteúdo: peso + remédios em cima, metabolismo recolhido embaixo.
- Criança: escore-z. Adulto: kg/semana ou % por paciente; padrões 0,2 kg/semana, 1% em 4 semanas,
  0,05 de escore-z em 4 semanas; janela de 4 semanas.
- Abordagem A.

- **Escore-z sem altura** (aprovado): pesagem sem altura usa a última altura medida nos **90 dias anteriores**
  (com a correção deitado/em pé daquela medida); sem altura nesse prazo, a pesagem fica fora da curva de escore-z.
- Desenho aprovado em 2026-11-19.

## Para quem construir
- Formato do JSON do gráfico, como alinhar faixas e curvas no mesmo eixo (plugin próprio do Chart.js ou
  segundo gráfico com o mesmo min/max), nomes de classes, onde fica o cálculo (sugestão: `app/Evolution/`,
  com o cálculo puro testável separado do controlador), limites de validação dos campos do critério.
