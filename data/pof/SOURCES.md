# Medidas caseiras (POF/IBGE): origem

Referência: IBGE, Diretoria de Pesquisas, Coordenação de Trabalho e Rendimento. *Pesquisa de Orçamentos Familiares 2008-2009:
Tabela de Medidas Referidas para os Alimentos Consumidos no Brasil*. Rio de Janeiro: IBGE, 2011.

## Arquivo bruto (baixado sem alteração em 2026-11-17)
- URL: https://biblioteca.ibge.gov.br/visualizacao/livros/liv50000.pdf (3.032.908 bytes, 546 páginas)

```
317ace59e53e25f3fc4913c5f552adf181bf001e6272edb5ae7a46804aa360b0  liv50000.pdf
```

O PDF não é versionado no repositório. `medidas.csv` foi extraído do texto do PDF (`pdftotext -raw`) e cada linha foi
copiada sem alteração dos valores; as páginas citadas foram conferidas contra a imagem renderizada da página.

## `medidas.csv`
Colunas: `taco_code,medida,medida_plural,gramas,pagina_pof`.
- `pagina_pof` = número da página **do arquivo PDF** (1 = capa), onde a linha da tabela aparece. A tabela é impressa em páginas
  giradas, sem numeração visível.
- `medida` = nome do "tipo de medida" da POF em minúsculas (ex.: `Colher de sopa` -> `colher de sopa`). `medida_plural` é só a
  flexão de número do mesmo nome (não vem da POF).
- `gramas` = coluna "Quantidade (g)" da POF, sem conversão. Para líquidos (leite, café) a POF informa gramas ou mililitros
  sem distinguir; tratamos como gramas.
- A ordem das linhas por alimento define o `id` da medida (`taco_code * 100 + n`); não reordenar nem apagar linhas já
  publicadas, só acrescentar ao fim do alimento.

## Regra de casamento POF -> TACO
A POF não usa os códigos da TACO. Cada alimento TACO foi ligado manualmente ao item POF equivalente e, quando o item POF tem
preparações, à preparação equivalente ao estado do alimento TACO (cozido, cru, grelhado, frito). Só entram medidas caseiras
usuais (colheres, concha, escumadeira, prato, copo, xícara, unidade, fatia, metade...). Ficam de fora gramas, quilo, litro, mililitro,
"porção" (Guia Alimentar) e medidas cuja referência POF é de outro alimento (ex.: "colher de sopa" do iogurte, referência de creme de leite).

| TACO | Alimento TACO | Item POF (código) e preparação |
|---|---|---|
| 1 | Arroz, integral, cozido | Arroz integral (6300201) |
| 3 | Arroz, tipo 1, cozido | Arroz (polido, parboilizado) (6300101) |
| 561 | Feijão, carioca, cozido | Feijão (preto, mulatinho, roxo, rosinha, etc.) (6303102); a POF não tem item só para carioca |
| 53 | Pão, trigo, francês | Pão de sal (8000105); a referência POF é "Pão francês" |
| 52 | Pão, trigo, forma, integral | Pão integral (8001401) |
| 458, 457 | Leite de vaca integral, desnatado UHT | Leite de vaca integral (7900101), desnatado (7903601) |
| 448, 449 | Iogurte natural, natural desnatado | Iogurte natural (7901204), desnatado (7901203) |
| 461, 463 | Queijo minas frescal, mozarela | Queijo de minas (7902001), muçarela (7901801) |
| 488, 490 | Ovo de galinha cozido, frito | Ovo de galinha (7803301), preparações Cozido(a), Frito(a) |
| 410 | Frango, peito sem pele, grelhado | Peito de galinha ou frango (7800401), Grelhado(a) |
| 377, 346 | Patinho, contra-filé sem gordura, grelhados | Patinho (7100501), Contrafilé (7100201), Grelhado(a) |
| 88, 91, 129 | Batata-doce, batata inglesa, mandioca cozidas | Batata-doce (6400401), Batata-inglesa (6400101), Mandioca (6400601), Cozido(a) |
| 109, 110 | Cenoura cozida, crua | Cenoura (6401201), Cozido(a) / Cru(a) |
| 78, 79 | Alface crespa, lisa | Alface (6700101) |
| 157 | Tomate, com semente, cru | Tomate (6705101) |
| 182 | Banana, prata, crua | Banana (ouro, prata, d'água, da terra, etc.) (6801101), Cru(a) |
| 222 | Maçã, Fuji | Maçã (6803001) |
| 214, 208 | Laranja pêra, baía | Laranja (pera, seleta, lima, da terra, etc.) (6801801) |
| 226 | Mamão, Papaia | Mamão (6803101) |
| 7 | Aveia, flocos, crua | Aveia em flocos (6500401) |
| 551 | Tapioca, com manteiga | Tapioca de goma (6501516); referência POF é "Tapioca com coco - unidade", aproximação |
| 261, 262, 263 | Manteiga com/sem sal, margarina | Manteiga com ou sem sal (7901501), Margarina com ou sem sal (7901602) |
| 260, 272 | Azeite de oliva, óleo de soja | Azeite de oliva (8400101), Óleo de soja (8400301) |
| 494, 492 | Açúcar refinado, cristal | Açúcar (6906602) |
| 471 | Café, infusão 10% | Café (8501302) |

Macarrão cozido não consta na TACO (só cru), então não há medidas para macarrão. Alimentos fora desta lista ficam sem medida POF
(usa-se gramas ou medida própria da clínica).

## Conversão
`php tools/import_pof.php --sql-only` gera `database/seeds/food_2_pof_measures.sql` (`INSERT ... ON DUPLICATE KEY UPDATE`).
Sem `--sql-only` também carrega no banco. Recarregar não reativa medidas desativadas nem apaga medidas próprias (ids a partir de 100000).
