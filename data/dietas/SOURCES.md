# Dietas prontas: origem

`cardapio.php` foi escrito para a plataforma (cardápios-base por estação do ano, alimentos comuns no Brasil, todos da TACO).
`php tools/build_diets.php --sql-only` gera `database/seeds/food_4_diet_library.sql`: 2 versões (infantil 3–10 anos, 1.000–2.000 kcal;
adolescente/adulto, 1.000–3.000 kcal) × 52 semanas × níveis de 100 kcal = 1.664 dietas, cada uma a ±3 % do alvo (teste
`tests/Unit/DietBuilderTest.php`, que também acusa seed desatualizado). Valores nutricionais: TACO 4ª ed. com as correções de
`data/taco/overrides.csv`; medidas caseiras: POF/IBGE 2008–2009 (`data/pof/medidas.csv`).

Ao alterar `cardapio.php`, os alimentos ou as medidas, rode o gerador de novo e reaplique o seed (`tools/migrate.php`).
São sugestões iniciais: a profissional avalia e ajusta cada plano antes de publicar.
