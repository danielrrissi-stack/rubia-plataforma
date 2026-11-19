# Pendências da Fase 4b (receita e plano em PDF)

Pontos menores da revisão final, deixados para depois:

- Se a pasta de cache e a pasta temporária reserva não puderem ser criadas (open_basedir), o PDF sai mesmo assim, mas sem cache de fontes e sem nenhum aviso no log.
- Nome do arquivo: um nome feito só de caracteres removidos gera `Receita -  - data.pdf`; UTF-8 inválido faz o nome sumir; caracteres de formatação (ex.: U+202E) não são removidos. Sugestão: remover `\p{Cf}` e usar "paciente" quando o nome ficar vazio.
- A auditoria é gravada antes de gerar o PDF (como na impressão): se a geração falhar, o log diz "pdf" e o usuário vê erro 500.
- Falta um teste de PDF longo para plano estruturado (verificado à mão: 18 páginas, 0,7 s).
- O teste de `?totais=1` na rota PDF compara pouco; a garantia real está no teste do HTML de `_plan_doc.php`.

Fora desta fase (decisão da usuária): linha do tempo, painel de evolução, exportação LGPD.
