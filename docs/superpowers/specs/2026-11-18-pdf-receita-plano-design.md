# Receita e plano alimentar em PDF — design (Fase 4b)

## Intenção
A Dra. Rubia e a equipe precisam de um **arquivo PDF gerado pelo sistema** (não "Imprimir" do navegador) da receita e do plano alimentar, para **mandar ao paciente** (WhatsApp/e-mail). Linha do tempo, painel de evolução e export LGPD saem da 4b e ficam para depois (decisão da usuária).

## Objetivos
- Botão **"Baixar PDF"** ao lado de "Imprimir" na receita (`/pacientes/{id}/receita/imprimir?p[]=..`) e no plano (`/planos/{planId}/imprimir`).
- O PDF reproduz a página de impressão atual (mesmo conteúdo, aparência equivalente), em A4.
- Nome do arquivo: `Receita - <nome> - dd-mm-aaaa.pdf` e `Plano alimentar - <nome> - dd-mm-aaaa.pdf` (nome social se houver; caracteres inválidos para arquivo removidos).

## Fora do escopo (não-objetivos)
- Assinatura digital ICP-Brasil (já fora do projeto): a receita sai com a linha de assinatura em branco. Remédio controlado continua exigindo receita assinada.
- Envio automático por e-mail/WhatsApp; PDF no portal da família; gráficos no PDF.

## Abordagens consideradas
1. **Dompdf (escolhida pela usuária)** — HTML → PDF reaproveitando os modelos de impressão; ~4 MB versionados; usa extensões `dom` e `mbstring`.
2. TCPDF — API própria, mais trabalho para igualar a impressão, ~15 MB.
3. FPDF — minúscula, mas layout manual e quebra de página trabalhosa em planos longos.

## Decisões
- **Biblioteca:** Dompdf **v3.1.6** (pacote oficial `dompdf-3.1.6.zip` do GitHub, que já traz as dependências), versionada em `lib/dompdf/` com o autoload próprio dela; sem Composer em produção. Fontes extras que não usamos podem ser removidas para reduzir o tamanho (manter DejaVu Sans para acentos e "—").
- **Segurança:** `isRemoteEnabled=false`, `isPhpEnabled=false`, `isJavascriptEnabled=false`, `chroot` restrito à pasta da biblioteca + CSS; cache de fontes em pasta gravável do storage (fora da raiz pública). Nenhum dado do paciente vai a serviço externo.
- **Mesmas permissões e auditoria das páginas de impressão:** receita só médico; plano quem hoje pode imprimir. Registro de auditoria igual ao da impressão, marcando `pdf: true`.
- **Plano em PDF = versão para a família** (sem o quadro de totais/calorias), porque o PDF é para mandar ao paciente. A opção com totais continua só na impressão.
- **Rascunho:** plano em rascunho não gera PDF (só publicado) — o PDF é o que vai ao paciente. *(minha escolha)*
- **CSS do PDF:** folha própria simples (`pdf.css`, sem flex/grid, que o Dompdf não suporta), lida do disco; o modelo HTML é compartilhado com a impressão (o botão e o link "Voltar" não entram no PDF).
- **Resposta:** `Content-Type: application/pdf`, `Content-Disposition: attachment` com o nome acima (com `filename*` UTF-8), `Cache-Control: no-store`.
- **Requisitos:** o verificador de requisitos (instalador) passa a checar a extensão `dom`; o README ganha a nota de atualização. O pacote de release inclui `lib/`.
- **Testes:** gerar o PDF de verdade nos testes (começa com `%PDF-`, contém o nome do paciente/medicamento ao extrair texto quando possível), permissões (403/404 iguais à impressão), nome do arquivo, plano rascunho recusado, auditoria gravada.

## Deixado para quem constrói
Organização interna (um serviço `App\Pdf\PdfRenderer` compartilhado), detalhes do CSS, como compartilhar o modelo entre impressão e PDF.
