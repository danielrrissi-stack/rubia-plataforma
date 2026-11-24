# Crescimento: gráficos por faixa e resumo do IMC

**Tamanho:** mudança pequena, sem migração. O usuário escolheu a opção A e pediu para começar.

- **Painel novo** abaixo das Curvas (que não mudam): altura, peso e IMC para a idade, conforme `modelo_grafico` (fora do Git), com faixas coloridas pelos cortes de `NutritionalStatus`. Faixas etárias 0-2, 2-5, 5-10 e 10-19; abas só quando há medidas em mais de uma. Sem peso para a idade depois dos 10 anos (a OMS não tem). Adulto: sem painel.
- **Resumo do IMC**: última medida com peso e altura; categoria, cor e peso mínimo/máximo. Opção A: Eutrofia da OMS (z de -2 a +1) na idade da medida; adulto 18,5 a 24,9. Limites arredondados para dentro da faixa.
- **ABRAN** (fonte do exemplo, indicada pelo usuário): faixa "Normal" de 6 a 15 anos só como comparação. O mínimo dela fica perto da mediana da OMS, por isso não classifica. Meninos de 8 anos fora (a página traz 15,6 a 16,7, incoerente com 7 e 9 anos).
- **Acesso**: médica e nutricionista; o portal da família não muda.

**Abordagens:** Chart.js já instalado com script próprio (escolhida); estender `growth-chart.js` (mexeria no gráfico atual e no portal); SVG no servidor (sem interação).
