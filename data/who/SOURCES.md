# Tabelas LMS da OMS: origem

Os arquivos foram baixados sem alteracao (TAB, CRLF, com cabecalho) dos repositorios oficiais da OMS em 2026-11-01.

## OMS 2006 (Child Growth Standards, 0 a 5 anos)
Repositorio `WorldHealthOrganization/anthro`, commit `b776d8a12b1c97369c748b561159fd2ec4f4db58` (branch master), pasta `data-raw/growthstandards/`.
Base: `https://raw.githubusercontent.com/WorldHealthOrganization/anthro/b776d8a12b1c97369c748b561159fd2ec4f4db58/data-raw/growthstandards/`

| Arquivo | who_lms | x | Faixa |
|---|---|---|---|
| weianthro.txt | wfa | idade em dias | 0 a 1826 |
| lenanthro.txt | hfa | idade em dias | 0 a 1826 (coluna loh: L/H) |
| bmianthro.txt | bfa | idade em dias | 0 a 1826 (coluna loh: L/H) |
| hcanthro.txt | hcfa | idade em dias | 0 a 1826 |
| wflanthro.txt | wfl | comprimento em cm | 45 a 110 (passo de 0,1) |
| wfhanthro.txt | wfh | estatura em cm | 65 a 120 (passo de 0,1) |

## OMS 2007 (Growth Reference, 5 a 19 anos)
Repositorio `WorldHealthOrganization/anthroplus`, commit `7cfcdb39026e9a55de55732bc3cf14c82261bcf7` (branch main), pasta `data-raw/growthstandards/`.
Base: `https://raw.githubusercontent.com/WorldHealthOrganization/anthroplus/7cfcdb39026e9a55de55732bc3cf14c82261bcf7/data-raw/growthstandards/`

| Arquivo | who_lms | x | Faixa |
|---|---|---|---|
| bfawho2007.txt | bfa | idade em meses | 60 a 229 |
| hfawho2007.txt | hfa | idade em meses | 60 a 229 |
| wfawho2007.txt | wfa | idade em meses | 60 a 121 |

Os programas oficiais usam as tabelas assim:
- anthro: idade em dias, somente com menos de 60 meses (linhas 0 a 1826).
- anthroplus: interpola entre o mes inteiro e o seguinte, valido para 60 <= meses < 229 (peso/idade: < 121). Por isso as linhas 229 e 121 existem.

## SHA-256
```
70ef3260df08e48ce560c28cff4e733db78fb952cbd881eba63c9d3a15f38a6a  bfawho2007.txt
78a4364e99303114fb2c458d28d64c6d10ac33832a5d5b2d437bc5cea1c93a0d  bmianthro.txt
e794e46f06b91223ad2c6435148dc08794a1d75b67613a652c3151201a98bf7c  hcanthro.txt
9ac02aa4908f8ec3c6ba2362a4c7e1356043408b2873548ca22eedc016c235ad  hfawho2007.txt
709f7a11881451daf7820f022d363d5bdb93746b5361d6bd9218af6ff838e0c2  lenanthro.txt
bc15a6a623dd1d5beaeed1497666332aa54bc4ccd15ff9658c487d79694ab77b  weianthro.txt
9234dc640ba54001a9afd42ef15ab675b211a721c8f3c0a07c3431fd9cea0e1c  wfawho2007.txt
0050d31041c2f7d4f8a34f27e8066fd73a24806835b9e4a0de1a7ee46d54d582  wfhanthro.txt
ad470cf41b147bd2a16026e57fd17968df7bf746b9f0bf2ff85df95239643778  wflanthro.txt
```

## Importacao
`php tools/import_who.php` grava `who_lms` (REPLACE, idempotente) e gera `database/seeds/who_lms.sql`, que pode ser importado pelo phpMyAdmin em producao. Com `--sql-only`, apenas gera o arquivo.
