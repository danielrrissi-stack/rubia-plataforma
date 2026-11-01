# Plataforma de Acompanhamento Clínico e Nutricional — Dra. Rubia

**Data:** 2026-11-01
**Domínio:** rubia.med.br
**Status:** design para aprovação (revisado)

## 1. Intenção e porquê

A Dra. Rubia atende crianças, adolescentes (foco em obesidade infantil e do adolescente) e adultos. Hoje o acompanhamento depende da memória dos pacientes/responsáveis entre consultas. A plataforma deve:

- Ser o **prontuário clínico** da médica (crescimento, exames, prescrições, evolução).
- Ser uma **ferramenta de acompanhamento contínuo entre consultas**: pacientes adultos ou responsáveis registram peso, medidas, alimentação e hábitos, e veem orientações, metas e plano alimentar.
- Oferecer **montagem de plano alimentar** no estilo WebDiet / Dietbox / Easy Health (tabela de alimentos, cálculo de macros, substituições).

Sucesso = na consulta, a médica abre o paciente e vê a evolução (curvas, exames, diário da semana, adesão às metas) sem precisar perguntar de memória; e a família usa o app no dia a dia.

## 2. Objetivos, não-objetivos e anti-objetivos

**Objetivos**
- Três ambientes: painel clínico (equipe), app do paciente adulto/responsável, área da criança/adolescente.
- Cálculos corretos: IMC por idade e sexo, percentil e escore Z pelas referências OMS; TMB/GET; somatório nutricional do plano.
- Controle de acesso por perfil e por paciente; trilha de auditoria completa.
- Funcionar em computador e celular (web responsiva + PWA instalável).

**Não-objetivos (nesta versão)**
- App nativo de loja (iOS/Android).
- Certificação SBIS/CFM e assinatura digital ICP-Brasil (ver §9).
- Integração com WhatsApp e laboratórios (WhatsApp entra no módulo 9, dependente de serviço externo pago).
- Teleconsulta por vídeo, faturamento, convênios, emissão fiscal.
- Micronutrientes completos e lista de compras no plano alimentar (podem vir depois).

**Anti-objetivos (seria fracasso mesmo funcionando)**
- O sistema **sugerir, recomendar ou ajustar doses de medicamentos** (ex.: tirzepatida) automaticamente. Decisão é sempre do médico; o sistema só registra.
- Nutricionista ou secretária vendo dados clínicos que não lhes foram liberados.
- Alteração de prontuário sem registro de quem/quando/o quê, ou exclusão física de registros clínicos.
- Fotos, exames ou dados acessíveis por URL direta sem autenticação.
- Interface para pais com jargão médico difícil.

## 3. Restrições

- **Hospedagem compartilhada com cPanel**: PHP 8.x, MySQL/MariaDB, sem SSH garantido, sem processos permanentes, sem Node no servidor. Deploy por upload de arquivos (FTP/Gerenciador de arquivos) + importação SQL via phpMyAdmin.
- Tarefas agendadas apenas via **Cron do cPanel**.
- Sem framework pesado nem dependência de Composer em produção (se alguma biblioteca for usada, ela vai versionada junto no repositório, ex.: gerador de PDF).
- HTTPS obrigatório (SSL AutoSSL/Let's Encrypt do cPanel).
- Idioma: português do Brasil. Fuso: America/Sao_Paulo.
- Dados de menores: exigem consentimento do responsável (LGPD art. 14).

## 4. Perfis e permissões (decididos)

| Perfil | Acesso |
|---|---|
| **Médica (admin)** | Tudo. Cria usuários da equipe, libera pacientes para profissionais, configura o sistema, exporta prontuários. |
| **Nutricionista** | Somente pacientes **liberados pela médica** para ela. Somente a parte nutricional: nome e idade/sexo, antropometria (peso, altura, circunferências, curvas, IMC), TMB/GET, plano alimentar, diário alimentar, metas nutricionais, mensagens de nutrição. |
| **Secretária** | Agenda e dados cadastrais (nome, contatos, documentos, responsáveis). Nenhum dado clínico. |
| **Paciente adulto** | O próprio cadastro. Vê o que a equipe liberou; registra peso, medidas, diário alimentar e de hábitos, administração de medicação. |
| **Responsável** | Igual ao paciente adulto, para cada criança/adolescente vinculado (um responsável pode ter vários filhos; uma criança pode ter mais de um responsável). |
| **Criança/adolescente** | Login opcional criado pelo responsável/médica. Metas, atividades, conteúdo educativo adequado à idade. Sem acesso ao prontuário. |

**Escolhas minhas (a confirmar na leitura):**
- Nutricionista **não** vê diagnósticos, medicamentos, exames laboratoriais nem evolução médica. Vê as metas combinadas.
- A liberação para nutricionista é por paciente e pode ser revogada a qualquer momento (registrado na auditoria).
- Permissões implementadas de forma que um futuro perfil (ex.: psicólogo) seja adicionado sem reescrever o sistema (perfis × seções do prontuário).
- O que a família vê: aparece automaticamente o plano alimentar vigente, as metas, as prescrições ativas (nome, dose, orientação), a agenda, os gráficos de crescimento/peso e os próprios registros. **Exames** só aparecem depois que a médica marcar como "liberado". Diagnósticos, evoluções, anamnese e histórico familiar **nunca** aparecem para a família.
- "Metas" da nutricionista = só metas nutricionais e de hábitos alimentares (água, refeições, qualidade alimentar). Ela não vê metas médicas nem registros de administração de medicação.
- **Adolescentes e maioridade (decidido: a médica decide caso a caso):**
  - Por paciente adolescente, a médica configura se os responsáveis veem os registros feitos pelo próprio adolescente e pode marcar itens específicos (metas, mensagens, orientações) como "sigiloso — não visível ao responsável".
  - Ao completar 18 anos, o sistema **alerta a médica** (não age sozinho). Ela escolhe entre encerrar o acesso do responsável, transferir a conta para o paciente ou manter o vínculo. Tudo fica registrado na auditoria.
- Papéis separados: **admin** (configuração e usuários) e **médico** (clínico). A Dra. Rubia tem os dois. Cada paciente tem um "profissional responsável", para que outro médico possa entrar no futuro sem refazer o modelo.

## 5. Módulos (ordem de construção decidida)

Antes dos módulos, uma **fundação**: autenticação, perfis/permissões, auditoria, layout responsivo, upload seguro de arquivos, backup.

### Módulo 1 — Cadastro e prontuário
- Dados do paciente e responsáveis; vínculo responsável↔paciente.
- Histórico familiar (obesidade, diabetes, HAS, dislipidemia, etc.), histórico gestacional e de nascimento (peso/comprimento ao nascer, IG, tipo de parto, aleitamento) — exibido só para pacientes pediátricos.
- Antecedentes clínicos, alergias, comorbidades, tratamentos anteriores, medicamentos em uso.
- Consultas/evoluções: anamnese, exame físico, diagnóstico (texto + CID-10 opcional), conduta, plano terapêutico, encaminhamentos. Cada registro assinado pelo usuário logado com data/hora.
- Registros clínicos não são apagados; correções geram nova versão, preservando a anterior.
- Termo de consentimento (LGPD) com aceite registrado (data, IP, quem aceitou).

### Módulo 2 — Crescimento, antropometria e gasto energético
- Peso, altura/comprimento, circunferência abdominal, quadril, perímetro cefálico (0–2 anos), pressão arterial — registrados pela equipe ou pela família (marcando a origem do dado).
- **Pediátrico (0–19 anos):** IMC/idade, peso/idade, altura/idade (e peso/comprimento até 2 anos) com **percentil e escore Z pelo método LMS** usando:
  - OMS 2006 (Child Growth Standards) para 0–5 anos;
  - OMS 2007 (Growth Reference) para 5–19 anos.
  - Tabelas LMS oficiais importadas no banco (por sexo, idade em dias/meses).
  - Classificação de estado nutricional conforme OMS/SBP (ex.: obesidade grave Z > +3).
  - Gráficos com as curvas de referência (Z −3 a +3 ou percentis) e os pontos do paciente.
  - Velocidade de crescimento (cm/ano) entre medições.
- **Adulto (≥ 20 anos):** IMC com classificação OMS, circunferência abdominal com risco, relação cintura/quadril, gráfico de evolução de peso.
- **Pressão arterial pediátrica:** classificação por percentil para idade, sexo e percentil de altura (diretriz AAP 2017 / SBP); adulto pela diretriz brasileira vigente.
- **TMB/GET:** Mifflin-St Jeor, Harris-Benedict, FAO/OMS (Schofield; serve para crianças). O sistema mostra todas, a profissional escolhe uma e o fator de atividade; o resultado vira a meta energética do plano alimentar.

### Módulo 3 — Plano alimentar
- Base de alimentos: **Tabela TACO (UNICAMP)** importada. Como a TACO não traz medidas caseiras, elas vêm da **tabela de medidas referidas da POF/IBGE 2008–2009** (pública), editáveis pela profissional. *(escolha minha)* Profissional pode cadastrar alimentos e receitas próprios.
- Montagem por refeições (nome e horário), alimento + quantidade + medida caseira.
- Somatório em tempo real: kcal, proteína, carboidrato, gordura, fibra; percentual de macros; comparação com a meta do GET.
- Substituições por item (lista de equivalentes escolhidos pela profissional).
- Modelos de cardápio reutilizáveis.
- Orientações em texto livre anexas ao plano.
- Paciente/responsável vê o plano vigente no app; exportação em PDF.
- Histórico de planos (versão anterior preservada).

### Módulo 4 — Exames laboratoriais
- Cadastro de resultados: glicemia, HbA1c, insulina, colesterol total e frações, triglicerídeos, TGO, TGP e outros configuráveis (nome, unidade, faixas de referência por idade/sexo).
- Gráficos históricos por exame.
- Anexo de PDF/imagem do laudo.
- Liberação opcional para visualização pela família.

### Módulo 5 — Tratamento e medicamentos
- Prescrições: medicamento, dose, via, frequência, início, fim, observações; cada alteração de dose registrada como novo evento com data e profissional.
- Registro de efeitos adversos e intercorrências (pela equipe e relatados pela família).
- Família registra administração (data/hora, dose aplicada conforme prescrita).
- Data da próxima avaliação.
- **Nenhum cálculo, sugestão ou alerta de escalonamento de dose.**
- Impressão de receita simples em PDF (sem assinatura digital ICP-Brasil nesta versão).

### Módulo 6 — Diário de hábitos (família/paciente)
- Diário alimentar: refeição, horário, descrição, foto opcional; comparação lado a lado com o plano do dia.
- Água, sono (horários e qualidade), atividade física/brincadeiras, tempo de tela.
- A equipe solicita registros (ex.: "pesar toda segunda") e vê o resumo semanal antes da consulta.
- A profissional pode comentar registros do diário.

### Módulo 7 — Metas e acompanhamento familiar; área da criança
- Metas definidas pela equipe (sono, atividade, telas, qualidade alimentar, rotina de acompanhamento) com frequência e período.
- Família marca cumprimento; painel semanal de progresso.
- Área da criança: metas em linguagem lúdica, conquistas visuais, conteúdo educativo publicado pela equipe, separado por faixa etária.

### Módulo 8 — Linha do tempo e gráficos de evolução
- Linha do tempo única por paciente: consultas, exames, alterações de conduta/dose, medições, metas alcançadas.
- Painel de evolução metabólica (antropometria + exames + PA).
- Relatórios e exportação do prontuário completo em PDF.

### Módulo 9 — Comunicação e agenda
- Agenda de consultas e retornos (secretária e médica); família vê seus agendamentos.
- Mensagens família ↔ clínica, separadas por tipo (clínica, nutrição, administrativa) e roteadas conforme permissões.
- Notificações por e-mail (SMTP da hospedagem) de consultas e retornos.
- WhatsApp: integração via serviço externo (API oficial ou provedor) — escopo a definir quando chegar aqui, pois tem custo.

## 6. Segurança, auditoria e LGPD

- Senhas com `password_hash` (bcrypt/argon2); bloqueio após tentativas falhas; recuperação por e-mail.
- Autenticação em dois fatores (TOTP) **obrigatória para a equipe** e opcional para famílias. *(escolha minha)*
- Sessões seguras (cookies HttpOnly, Secure, SameSite), proteção CSRF, consultas SQL preparadas (PDO), escape de saída (XSS).
- **Trilha de auditoria**: toda leitura de prontuário e toda criação/alteração de dado clínico gravada (usuário, data/hora, IP, entidade, valores antes/depois). Tabela só de inserção.
- Exclusão lógica para dados clínicos; nada é apagado fisicamente pela interface.
- Arquivos (fotos, laudos) fora da pasta pública, servidos por script que verifica permissão.
- Criptografia em trânsito (HTTPS). **Sem criptografia campo a campo no banco** nesta versão: em hospedagem compartilhada não há lugar seguro para guardar a chave, e perder a chave significaria perder prontuários que precisam ser guardados por 20 anos. A proteção fica no controle de acesso, na auditoria, nos arquivos fora da pasta pública e no banco não exposto. *(escolha minha)*
- **Backup** diário via Cron: dump do banco + arquivos, compactado e criptografado com uma **senha de backup definida pela Dra. Rubia na instalação**. Ela guarda essa senha fora do sistema (impressa/cofre); o sistema avisa que sem ela não dá para restaurar. Guardado fora da pasta pública, retenção de 30 dias, com download periódico recomendado para cópia externa. *(escolha minha)*
- Recursos da hospedagem: o instalador verifica o que está disponível (pasta fora de public_html, `exec`/`mysqldump`, limites de upload) e usa alternativas quando faltar (dump em PHP puro; pasta protegida por `.htaccess`; fotos redimensionadas no upload para economizar espaço; fila de e-mails processada pelo Cron para respeitar limites de envio). *(escolha minha)*
- Exportação do prontuário de um paciente (PDF) e dos dados do titular a pedido (direito de acesso LGPD).
- Termo de consentimento e política de privacidade exibidos no primeiro acesso.
- Registro de qual profissional foi responsável por cada atendimento.

## 7. Decisões técnicas (minhas, como construtor)

- PHP 8.1+ puro, organizado em MVC leve com roteador próprio e front controller (`public/index.php`); código da aplicação fora da raiz pública quando o cPanel permitir (senão, protegido por `.htaccess`).
- MySQL/MariaDB com InnoDB, utf8mb4; migrações em arquivos SQL numerados.
- Frontend: HTML renderizado no servidor + CSS próprio responsivo + JavaScript leve; gráficos com Chart.js (arquivo local); PWA com manifest e service worker simples (sem modo offline de dados nesta versão).
- PDF: biblioteca PHP versionada no repositório (ex.: Dompdf ou TCPDF).
- Testes automatizados (PHPUnit local) obrigatórios para os cálculos: LMS/escore Z/percentil, IMC, TMB/GET, PA pediátrica, somatório nutricional — validados contra valores publicados pela OMS.
- Instalador simples (script de primeira execução) que cria o usuário médico administrador e verifica requisitos do servidor.

## 8. Identidade visual

Interface limpa, acolhedora, com linguagem simples para as famílias; painel clínico mais denso e objetivo. Nome e marca da Dra. Rubia no cabeçalho. Paleta e logotipo a definir com a Dra. Rubia (o builder usa uma paleta neutra suave até lá).

## 9. Limites legais (registrado explicitamente)

- O sistema será construído **seguindo os requisitos** da Resolução CFM 1.821/2007 (e normas correlatas) e da LGPD: identificação do profissional, integridade, auditoria, controle de acesso, backup, guarda.
- Ele **não terá certificação SBIS/CFM** nem assinatura digital ICP-Brasil (NGS2). Sem isso, **não é permitido eliminar o prontuário em papel / documento assinado**; a decisão de como usar o sistema perante o CFM é da Dra. Rubia.
- **Sem migração de dados:** o sistema começa do zero; histórico antigo é digitado aos poucos (ex.: medições antigas lançadas com a data original, marcadas como "registro retroativo").
- Prazo de guarda do prontuário: mínimo 20 anos a partir do último registro (Lei 13.787/2018) — o sistema não deve permitir expurgo antes disso.
- A Dra. Rubia é a controladora dos dados (LGPD); a hospedagem é operadora — recomenda-se verificar o contrato da hospedagem quanto a localização e segurança dos dados.

## 10. Fica a critério do construtor

- Estrutura exata de tabelas, rotas e pastas.
- Layout detalhado das telas, desde que siga §8 e o espírito da tela inicial proposta (cartões: Crescimento, Alimentação, Atividades, Tratamento, Exames, Consultas; metas da semana).
- Escolha de bibliotecas pequenas versionadas (PDF, gráficos, TOTP).
- Divisão interna de cada módulo em entregas menores.
