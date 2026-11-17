# Backlog da Fase 2b (exames) - pendências menores

## Validação e dados
- LabValue: o ramo de texto aceita qualquer texto começando com < > ≤ ≥ (apertar).
- ageMonths com datas em ordem invertida.
- Valores das faixas enviados como strings.
- Liberar/excluir não atualizam updated_at.

## Arquivos
- Content-Disposition sem filename* para nomes com acento.
- Arquivos órfãos se o commit externo falhar.

## Gráfico
- Corrida de fetch ao clicar rápido no gráfico.
- Pontos sem faixa são desenhados em verde, sem o texto "sem faixa".
- Legenda do gráfico poluída.

## Catálogo (formulário)
- Sem teste de ida e volta (round-trip) do formulário.
- "+ faixa" deixa de funcionar depois de remover todas as linhas.
- Sem linha em branco após erro de validação.
- Pontos de corte negativos são aceitos.
- Erros aparecem em duas rodadas.
- Botões de excluir sem confirmação.
- Toggle "+ outro exame" não foi construído (todos os exames ativos são listados).

## Portal
- A home do portal monta a familyView inteira só para testar se está vazia.

## Testes
- PortalExamsTest: assertiva frágil (assertStringNotContainsString('30')).
- php -S (servidor de desenvolvimento) dá 404 em rotas *.json; usar um router script nos testes locais. Já existia antes e afeta também curvas.json.
