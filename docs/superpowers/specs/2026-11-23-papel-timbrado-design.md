# Papel timbrado (Receita, Pedido de Exames, Anotações)

**Tamanho:** mudança pequena. Sem migração de banco.

## Intenção
Folhas A4 timbradas, iguais ao `modelo_papel/Modelo_geral.png`, para a médica imprimir e preencher **à mão**
(receitas, pedidos, anotações e explicações que não entram no sistema).

## Objetivos
- Três papéis, iguais exceto pelo título: Receita, Pedido de Exames, Anotações.
- `/admin/receita`: três botões antes do formulário; papel com paciente, nascimento e data em branco (linhas do modelo).
- `/pacientes/{id}`: três botões bem no final da página; papel com nome, nascimento e data de hoje preenchidos.
- Figuras redesenhadas em vetor: logo, ícones, ondas, marca-d'água, frase e coração.

## Fora do escopo / não pode acontecer
- Nada é gravado na ficha: sem histórico, linha do tempo ou vínculo com prescrições e exames.
- Não há texto digitado no meio da folha.
- A receita com medicamentos (`/pacientes/{id}/receita`) e os atestados não mudam.
- Uma folha só: nome ou endereço longos não podem criar a segunda página.

## De onde vem cada dado (decidido pelo usuário)
| Dado | Origem |
|---|---|
| "Clínica Crescer em Família" | fixo, parte do desenho |
| Nome, especialidade, CRM, RQE | cadastro do usuário logado |
| WhatsApp, endereço, cidade, CEP | cabeçalho da receita, como cadastrado |
| Site www.rubiapediatra.com.br, Aplicativo rubia.med.br | fixo |
| Paciente e nascimento | cadastro do paciente (só na ficha) |

Rótulo "WhatsApp" (corrigido). Título em cada papel (aprovado).

## Abordagens
1. **PDF no servidor (Dompdf já existente) + SVG vetorial — escolhida**: nítido, igual em todo aparelho, dentro do padrão do projeto.
2. Impressão pelo navegador: margens e fundos variam; ondas podem não imprimir.
3. PNG de fundo: cerca de 128 dpi, dados presos na imagem.

## Decisões de quem constrói (a conferir)
- Título centralizado, abaixo da data de nascimento.
- O PDF abre em aba nova, pronto para imprimir (não baixa direto).
- Dados vazios são omitidos, com o ícone; "CRM"/"RQE" não são duplicados se já estiverem no cadastro.
- Cidade na segunda linha do endereço, se o endereço ainda não a contiver.
- Nome do paciente: o social, se houver; senão o civil.
- Acesso: em `/admin/receita`, administrador; na ficha, só médico com acesso ao paciente (mesma regra da receita).
  Outros papéis não veem os botões e recebem 403 na URL.
- Abrir o papel de um paciente gera apenas o registro de auditoria de visualização (LGPD), como no resto do sistema.
- Sem cache do PDF (`no-store`).
- Fontes com licença livre, versionadas no projeto, em pasta só de leitura; letras manuscritas viram traços vetoriais.
- Textos do menu e da página passam a mencionar o papel timbrado; manual da médica e README atualizados, e os PDFs do manual regenerados.

## Testes
Três tipos e tipo inválido (404); permissões; campos preenchidos e em branco; dados do usuário logado e do cabeçalho;
escape de HTML; exatamente uma página com textos no limite; auditoria; botões nas duas telas.
Conferência visual do PDF contra o modelo.

## Publicação
Sem migração. Copiar `public/` (PDFs do manual) e refazer a linha `$APP_ROOT` do `index.php`.
