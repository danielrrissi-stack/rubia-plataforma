# Atestados e declarações — design

## Intenção

A médica emite, a partir da ficha do paciente, atestados e declarações em PDF prontos para imprimir,
assinar e carimbar, no padrão do modelo enviado pelo usuário (declaração de comparecimento da
Dra. Rúbia). O sistema preenche o que já sabe (nome, CPF, data, cabeçalho) e cada emissão fica
registrada na ficha, como pede o CFM (o atestado emitido deve constar do prontuário).

## Tipos (decididos pelo usuário)

1. **Comparecimento** — texto do modelo: data, período (hora início/fim), CID opcional.
2. **Acompanhante** — declara que o responsável (nome e, se informado, CPF) acompanhou o paciente
   no atendimento na data e período. CID opcional.
3. **Afastamento** — "necessita de N (por extenso) dia(s) de afastamento de suas atividades a partir
   de dd/mm/aaaa". N de 1 a 90. CID opcional.
4. **Aptidão para atividade física** — "foi examinado(a) nesta data e não apresenta, até o momento,
   contraindicação clínica para a prática de atividades físicas". Sem CID.

Todos têm um campo **Observação** opcional (até 500 caracteres), impresso como parágrafo próprio
(ex.: "liberado sem restrições", "evitar esforço intenso por 15 dias").

## CID

- Campo de código (formato CID-10, ex. `E66.9`, validado por padrão `^[A-Z][0-9]{2}(\.[0-9A-Z]{1,2})?$`)
  e uma caixa "O paciente ou responsável autorizou incluir o CID".
- CID preenchido sem a caixa marcada → erro de validação (não gera).
- Sem CID: não aparecem a linha "CID-10" nem a frase de autorização.
- Com CID: aparecem a linha e a frase "Documento emitido mediante autorização expressa do paciente
  ou de seu representante legal para inclusão do diagnóstico codificado (CID)."

## Abordagens consideradas

- **A. Textos fixos no código + observação (escolhida pelo usuário).** Rápido, padronizado, sem risco
  de perder frase obrigatória. Mudança de redação base passa por mim.
- B. Modelos editáveis pela administradora com marcadores — mais autonomia, mais trabalho e risco de
  marcador apagado.
- C. Texto livre editável a cada emissão — flexível, mas cada documento sai diferente e é fácil apagar
  a frase do CID.

## Documento (PDF)

Mesmo motor da receita (Dompdf / `Container::pdf()`), A4, uma página:

- **Cabeçalho:** nome da médica em maiúsculas; linha "Especialidade | CRM | RQE" (itens vazios omitidos).
- **Título** do tipo (ex.: DECLARAÇÃO DE COMPARECIMENTO, DECLARAÇÃO DE ACOMPANHANTE, ATESTADO MÉDICO,
  ATESTADO DE APTIDÃO PARA ATIVIDADE FÍSICA).
- **Corpo** com nome em negrito, CPF (frase do CPF omitida quando não cadastrado), datas por extenso
  ou dd/mm/aaaa como no modelo.
- Comparecimento termina com "Esta declaração comprova o comparecimento ao atendimento médico, não
  constituindo recomendação de afastamento das atividades por período adicional." (acompanhante usa
  a mesma ideia: "...comprova o acompanhamento...").
- **Rodapé:** "Cidade, dd de mês de aaaa." (cidade do cabeçalho da receita), linha de assinatura,
  nome, "CRM | RQE", "Assinatura e carimbo", telefone e endereço do consultório (cabeçalho da receita;
  vazios omitidos).

Nome do paciente: nome civil (vai junto com o CPF). Quando há nome social, imprime
"Nome social (nome civil: Nome civil)". *(minha decisão)*

## Cadastro da médica

Migration `016_certificates.sql` acrescenta a `users`: `specialty VARCHAR(80) NULL` e
`rqe VARCHAR(40) NULL`, editáveis na tela de usuários (equipe) junto do registro profissional.
`professional_register` já existe e é impresso como está (ex.: "CRM-SP 113058").

## Registro na ficha

Tabela `certificates`: id, patient_id, author_id, type (comparecimento | acompanhante | afastamento |
aptidao), issued_on (data do documento), payload JSON (campos digitados), body_html (corpo final
gravado), cid NULL, voided_at NULL, void_reason NULL, created_at. Sem MariaDB-only (MySQL 5.7).

- O PDF é sempre gerado a partir do texto gravado, então baixar de novo sai idêntico, mesmo que o
  cadastro mude depois.
- Não se apaga atestado. Um emitido por engano pode ser **anulado** com motivo; continua na lista
  marcado "anulado" e o PDF passa a sair com a marca "ANULADO". *(minha decisão)*
- Cartão "Atestados" na página do paciente (últimos emitidos + botão "Novo atestado"), e cada
  emissão entra na linha do tempo.
- Toda emissão, download e anulação é auditada.

## Quem pode

Só a médica (papel `medico`) com acesso clínico ao paciente (mesma checagem da receita:
`Clinician::assert` + `Policy::CLINICO`). Nutricionista, secretária, família e criança: 403 / sem
cartão. Entra na `SmokeAllRoutesTest`.

## Telas

- `GET /pacientes/{id}/atestados/novo?tipo=` — escolhe o tipo (4 botões) e mostra só os campos dele;
  data padrão hoje, período padrão vazio; acompanhante sugere o primeiro responsável vinculado.
- `POST /pacientes/{id}/atestados` — valida, grava, redireciona para a página do atestado.
- `GET /pacientes/{id}/atestados/{cid}` — prévia do texto + "Baixar PDF" + "Anular".
- `GET /pacientes/{id}/atestados/{cid}/pdf`, `POST .../anular`.
- `GET /pacientes/{id}/atestados` — lista completa.

## Manual

Seção "Atestados e declarações" no manual da médica (foto da clínica fictícia), regenerando fotos e PDF.

## Fora do escopo

Assinatura digital (ICP-Brasil), envio ao paciente pelo portal, modelos editáveis, outros tipos.

## Para o construtor

Nomes de classes/arquivos (sugestão: `app/Certificates`), número por extenso (1–90), layout CSS do
PDF dentro de `app/Pdf/pdf.css`.
