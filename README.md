# Rubia - acompanhamento nutricional

Sistema web (PHP + MySQL) para o acompanhamento de obesidade infantil e nutrição de adultos da Dra. Rubia.
Funciona em hospedagem compartilhada com cPanel, sem Composer e sem acesso por terminal.

## 1. Requisitos

- Hospedagem com cPanel, PHP 8.1 ou superior (recomendado 8.3) e MySQL/MariaDB.
- Extensões do PHP: `pdo_mysql`, `openssl`, `zip`, `mbstring`, `json`, `fileinfo` (o instalador confere e avisa).
- Domínio com HTTPS (SSL gratuito AutoSSL do cPanel). Exemplo usado abaixo: `rubia.med.br`.
- Um e-mail de envio (por exemplo `nao-responda@rubia.med.br`), criado no cPanel.
- Um aplicativo autenticador no celular (Google Authenticator, Microsoft Authenticator, Authy...) para o 2FA da equipe.

## 2. Instalação no cPanel (passo a passo)

Troque `USUARIO` pelo nome de usuário da sua conta no cPanel e `/home/USUARIO` pelo **diretório inicial** da conta,
exatamente como o cPanel mostra em "Informações gerais" → "Diretório inicial" (coluna da direita da página inicial).
Algumas hospedagens usam `/home2/`, `/home3/`…: por exemplo, `/home2/abc123` em vez de `/home/abc123`.
Um caminho errado faz o site dar erro 500 ("Failed opening required …/app/bootstrap.php" no log de erros).

> **Domínio adicional (o site não é o principal da conta).** Se a conta já tem outro site em `public_html/`
> e `rubia.med.br` foi adicionado como domínio extra, a raiz dele é uma pasta **dentro** de `public_html`,
> como `public_html/rubia.med.br/`. Nesse caso, **nunca copie nada do sistema direto em `public_html/`**:
> isso substitui o `index.php` e o `.htaccess` do outro site e ainda deixa `/instalar` aberto no endereço dele
> (ex.: `https://outrosite.com/instalar`). Se isso acontecer, restaure esses dois arquivos pelo backup do cPanel
> ("Backup" / "JetBackup") ou peça ao suporte.

1. **Criar o banco de dados.** No cPanel, abra "Bancos de dados MySQL". Crie um banco (ex.: `USUARIO_rubia`),
   crie um usuário com senha forte (ex.: `USUARIO_rubia`) e adicione o usuário ao banco com "Todos os privilégios".
   Anote o nome do banco, o usuário e a senha.
2. **Enviar o pacote.** Gere o pacote no computador de desenvolvimento com `php tools/build_release.php`
   (cria `dist/rubia-release-AAAAMMDD.zip`). No "Gerenciador de arquivos" do cPanel, crie a pasta
   `/home/USUARIO/rubia_app/`, envie o `.zip` para ela e use "Extrair". A pasta `rubia_app` fica **fora** de `public_html`.
3. **Publicar a parte pública.** Copie **o conteúdo** da pasta `rubia_app/public/` (incluindo o arquivo oculto `.htaccess`,
   a pasta `assets`, `index.php`, `manifest.json` e `sw.js`) para a **raiz do domínio**.
   Confira qual é ela no cPanel, em "Domínios" → "Raiz do documento": no domínio principal costuma ser `public_html/`;
   num domínio adicional costuma ser uma pasta com o nome dele, como `public_html/rubia.med.br/`.
   Nesse caso, use essa pasta no lugar de `public_html/` aqui e no passo 4, e não mexa no resto de `public_html/`
   (pode ser outro site). Não apague a pasta `.well-known/`, que é usada pelo certificado SSL.
   Se `.htaccess` não aparecer, em "Configurações" do Gerenciador de arquivos marque "Mostrar arquivos ocultos".
4. **Ajustar o caminho.** Abra o `index.php` da raiz do domínio no editor do cPanel e procure a linha marcada com `// AJUSTE NO DEPLOY`:

   ```php
   $APP_ROOT = dirname(__DIR__); // AJUSTE NO DEPLOY
   ```

   Troque por:

   ```php
   $APP_ROOT = '/home/USUARIO/rubia_app'; // AJUSTE NO DEPLOY
   ```

   Use o diretório inicial real da conta (ex.: `'/home2/abc123/rubia_app'`), entre aspas simples, sem barra no final.
   A pasta `rubia_app` precisa conter diretamente `app/`, `config/`, `database/`… (não uma subpasta extra criada ao extrair).
   Para conferir: `https://rubia.med.br/instalar` deve abrir a tela de instalação. Se der erro 500, veja as últimas linhas
   do arquivo `error_log` na raiz do domínio (ou em cPanel → "Métricas" → "Erros").

5. **Ativar o SSL.** No cPanel, em "SSL/TLS Status", rode o AutoSSL para o domínio e espere o certificado ficar ativo.
   O sistema só deve ser usado por `https://`: o `.htaccess` sempre redireciona `http://` para `https://`
   (exceto em `localhost`, para desenvolvimento) e envia o cabeçalho HSTS. Não há opção para desligar isso.
6. **Instalar, logo em seguida.** Abra `https://rubia.med.br/instalar` **imediatamente** depois de enviar os arquivos.

   **Por que imediatamente:** enquanto o sistema não está instalado, qualquer pessoa que abrir `/instalar` vê o formulário,
   e a página não pede senha. Quem enviar primeiro vira a médica administradora e escolhe o banco e a senha de backup.
   Faça os passos 2 a 6 numa mesma sessão, com os dados do passo 1 já anotados.

   **6.1. Confira a tabela "Requisitos do servidor"** no topo da página:

   | Item | Se falhar |
   |---|---|
   | PHP 8.1 ou superior | No cPanel, em "Selecionar versão do PHP" / "MultiPHP Manager", escolha 8.1 ou mais nova |
   | Extensões `pdo_mysql`, `openssl`, `zip`, `mbstring`, `json` | Ative em "Selecionar versão do PHP" → Extensões, ou peça à hospedagem |
   | Pasta de dados gravável | Verifique as permissões da pasta indicada |
   | Pasta config gravável | `rubia_app/config/` precisa permitir gravação (permissão 755) |
   | *(informativos)* pasta fora da raiz pública, `mysqldump`, HTTPS | Não impedem a instalação, mas leia o aviso |

   Se algum item **obrigatório** aparecer como "Falhou", o formulário não é exibido. Corrija o problema e recarregue a página.
   Confira também o caminho em "Pasta de dados gravável": deve ser `<diretório inicial>/rubia_storage`
   (ex.: `/home2/abc123/rubia_storage`). Se aparecer um caminho dentro de `public_html`, não instale.
   Em domínio adicional, confirme ainda que o endereço do outro site (ex.: `https://outrosite.com/instalar`)
   **não** abre esta tela; se abrir, há arquivos do sistema em `public_html/` (veja o aviso no início desta seção).

   **6.2. Preencha o formulário.**

   - *Banco de dados* (os dados anotados no passo 1):
     - **Servidor do banco:** deixe `localhost`, que já vem preenchido e é o padrão no cPanel.
     - **Nome do banco** e **Usuário do banco:** com o prefixo da conta, por exemplo `USUARIO_rubia`.
     - **Senha do banco:** a que você criou no cPanel.
   - *Site e e-mail:*
     - **Endereço do site:** já vem preenchido. Confirme que está `https://rubia.med.br`, sem barra no final.
       É esse endereço que vai nos links dos e-mails.
     - **E-mail remetente dos avisos:** por exemplo `nao-responda@rubia.med.br`. O e-mail precisa existir no cPanel;
       se não existir, as mensagens podem cair no spam ou nem ser enviadas.
   - *Médica administradora* (a conta principal):
     - **Nome completo**, **E-mail** (que será o login dela) e **CRM** (ex.: `CRM-SP 123456`).
     - **Senha:** no mínimo **10 caracteres**, digitada duas vezes.
   - *Senha de backup:* no mínimo **12 caracteres**, digitada duas vezes. Ela não é a senha de login: protege a chave
     que abre os backups criptografados. **Anote num cofre de senhas antes de clicar em Instalar.** Se ela se perder,
     nenhum backup poderá ser restaurado, e o sistema não tem como recuperá-la.

   **6.3. Clique em "Instalar".** O instalador:
   1. testa a conexão com o banco (se falhar, mostra "Não foi possível conectar ao banco de dados…"; confira os quatro campos do banco);
   2. cria as tabelas e carrega as tabelas de referência (curvas da OMS e pressão arterial da AAP);
   3. cria a pasta de dados (uploads, backups, chaves, logs) em `/home/USUARIO/rubia_storage/`, fora de `public_html` (mesmo quando a raiz do domínio fica dentro dela), ou,
      se isso não for possível, em `rubia_app/storage/`, protegida por `.htaccess`;
   4. gera o par de chaves de backup, protegido pela senha de backup;
   5. cria a conta da médica administradora e o termo de consentimento padrão;
   6. grava `config/config.php` e trava a instalação (arquivo `config/.install.lock`).

   Se aparecer um erro de validação (senha curta, e-mail inválido…), o formulário volta com os campos de texto preenchidos.
   **Só as senhas precisam ser digitadas de novo.**

   **6.4. Resultado.**
   - Aparece a tela **"Instalação concluída"**, com a chave `backup_private.pem`. **Não feche essa tela antes de fazer o passo 7.**
   - A partir daí, `/instalar` passa a mostrar "página não encontrada", e ninguém mais consegue reinstalar por cima.
   - No primeiro login, a médica é levada a configurar a verificação em duas etapas (2FA). Tenha o celular com o
     aplicativo autenticador por perto.

   **6.5. Se der errado no meio.**
   - Se a instalação falhar **antes** de gravar os dados (por exemplo, banco errado), basta corrigir e enviar de novo.
     A trava é liberada automaticamente.
   - Se aparecer *"Os dados foram gravados, mas não foi possível gravar a configuração…"*, apague todas as tabelas do banco
     (no phpMyAdmin) e o arquivo `rubia_app/config/.install.lock`, e repita o passo 6.
   - Se alguém instalar antes de você (`/instalar` mostra "página não encontrada" e o login não aceita a sua senha),
     apague `rubia_app/config/config.php`, `rubia_app/config/.install.lock` e as tabelas do banco, e instale de novo.
7. **Guardar a chave de backup.** Na tela final aparece o conteúdo de `backup_private.pem`. Copie e salve em um arquivo com
   esse nome, fora do servidor (pendrive, cofre de senhas), **junto com a senha de backup**. Essa tela não aparece de novo
   (a chave também pode ser baixada pela médica em "Backups"). Sem a chave e a senha, os backups não podem ser abertos.
8. **Configurar o Cron.** No cPanel, em "Tarefas Cron", adicione uma tarefa a cada 5 minutos:

   ```
   */5 * * * * /usr/local/bin/php /home/USUARIO/rubia_app/cron/run.php
   ```

   Use o caminho completo do PHP: na maioria dos cPanel é `/usr/local/bin/php` — confirme com a hospedagem
   (ex.: `/opt/cpanel/ea-php83/root/usr/bin/php` quando a conta usa uma versão específica do PHP).

   Ela envia os e-mails pendentes (convites, redefinição de senha) e gera o backup diário automaticamente.
   O mesmo Cron de 5 minutos também monta, uma vez por dia a partir das 06:00, a continuação dos planos alimentares por dias que estão terminando (rascunho para revisar, com aviso por e-mail).
9. **Conferir.** Acesse `https://rubia.med.br`, entre com o e-mail e a senha da médica e configure o 2FA (veja a seção 3).
   Se algo falhar, o registro de erros fica em `storage/logs/app.log` (dentro da pasta de dados).

Se o instalador avisar que as tabelas de referência não foram carregadas, importe manualmente pelo phpMyAdmin
(aba "Importar") os arquivos `database/seeds/who_lms.sql` e `database/seeds/bp_reference.sql`. Normalmente isso não é necessário.

## 3. Primeiro acesso

- **2FA obrigatório para a equipe** (médica, nutricionistas e secretárias). No primeiro login o sistema mostra um QR Code:
  leia com o aplicativo autenticador e digite o código de 6 dígitos.
- A médica cria os demais usuários em **Equipe**; cada pessoa recebe um e-mail com link para definir a senha (válido por 3 dias).
- Pacientes e responsáveis recebem o convite por e-mail, definem a senha e, no primeiro acesso, leem e aceitam o termo de
  consentimento (LGPD). Não precisam de 2FA.
- Sessões inativas são encerradas automaticamente (30 minutos para a equipe, 2 horas para as famílias).
- Para trocar o aplicativo autenticador (celular novo, com o antigo ainda em mãos), abra `/2fa/configurar`:
  o sistema pede primeiro o código atual e só então mostra o novo QR Code.

### Perdi o celular (2FA)

- **Usuário da equipe:** peça à médica administradora para abrir **Equipe** e clicar em **Redefinir 2FA** na linha do
  usuário. No próximo login a pessoa entra com e-mail e senha e cadastra o aplicativo de novo.
- **Única administradora (a própria médica):** a redefinição pela tela Equipe não vale para o próprio usuário. No cPanel,
  abra o phpMyAdmin, escolha o banco, aba "SQL", e execute (trocando o e-mail):

  ```sql
  UPDATE users SET totp_enabled=0, totp_secret=NULL WHERE email='email-da-medica@exemplo.com';
  ```

  Depois entre normalmente com e-mail e senha: o sistema mostra um novo QR Code para cadastrar o aplicativo.

## 4. Backups

- Um backup criptografado (`.rbk`) é gerado automaticamente a cada 24 horas pelo Cron e mantido por 30 dias.
  A médica também pode gerar um na hora e baixar os arquivos em **Backups**.
- Para abrir um backup são necessários dois itens guardados **fora do servidor**: o arquivo `backup_private.pem`
  e a **senha de backup** definida na instalação. Baixe um backup de vez em quando e guarde em outro lugar (computador, nuvem pessoal).
- **Restauração** (no computador com PHP instalado, na pasta do sistema):

  ```
  php tools/restore.php backup-AAAAMMDD-HHMMSS.rbk backup_private.pem saida.zip
  ```

  O programa pede a senha de backup e gera `saida.zip`. Extraia o zip: dentro estão `database.sql` e, se houver arquivos enviados, a pasta `storage/uploads/`.
  No cPanel, abra o phpMyAdmin, escolha o banco, aba "Importar", envie `database.sql`; depois, se existir, copie a pasta `uploads/`
  para a pasta de dados do sistema.
  Se `database.sql` for maior que o limite de upload do phpMyAdmin (aparece na aba "Importar"), use a importação do
  cPanel ("Backup" → restaurar banco MySQL, ou a ferramenta "MySQL" da hospedagem) ou peça à hospedagem para importar o arquivo.

## 5. Perfis e permissões

| Perfil | O que acessa |
|---|---|
| Médica (administradora) | Tudo: cadastro, crescimento, energia, prontuário, equipe, liberações, backups. |
| Nutricionista | Somente pacientes liberados pela médica: cadastro básico, medidas e curvas, metas de energia e nutrição (plano alimentar, alimentos, modelos). Não vê prontuário. |
| Secretária | Cadastro e agenda. Não vê dados clínicos (medidas, prontuário). |
| Responsável | Somente os filhos vinculados: crescimento, plano alimentar vigente e registro de peso/altura no portal da família. |
| Paciente adulto | Somente o próprio cadastro básico e medidas: crescimento e registro de peso no portal. |

Toda consulta e alteração de dados de pacientes fica registrada (quem, quando, o quê).
Registros clínicos nunca são apagados: correções criam nova versão e a anterior é mantida.

## Plano alimentar

O plano alimentar fica na ficha de cada paciente, no cartão **Plano alimentar** ("Ver plano alimentar"). Quem pode criar e editar:
a médica e as nutricionistas com o paciente liberado. A secretária não vê. A família vê somente o plano **vigente**, no portal, em "Alimentação".

### Criar um plano

Em **Novo plano** escolha:

- **Modo**: *Texto* (você cola ou digita o plano) ou *Estruturado* (refeições, opções e alimentos da tabela, com totais).
- **Começar de**: *Em branco*, *Cópia do plano vigente* (usa o mesmo modo do plano copiado) ou um *Modelo* da equipe.

Clique em **Criar rascunho**. Cada paciente tem **um rascunho por vez**; se já houver um, o sistema pergunta se você quer continuar
o rascunho ou descartá-lo e começar outro. O rascunho só é visto pela equipe até ser publicado.

### Modo texto

Cole o plano no campo "Plano". A pré-visualização ao lado mostra como vai ficar. O sistema entende estas convenções
(os botões "Título de refeição", "Item" e "Negrito" acima do campo inserem os marcadores):

- Linha terminada em `:` ou escrita toda em maiúsculas: vira o título de uma refeição (ex.: `CAFÉ DA MANHÃ (7h):` ou `Almoço:`).
  O botão "Título de refeição" insere `Nome da refeição:` já selecionado; é só digitar o nome por cima.
- `•` no começo da linha (também `-`, `*` ou `1.`): vira item de lista. Textos copiados do Word ou do PDF costumam funcionar.
- `**texto**`: negrito.
- Uma linha em branco separa parágrafos.

Há também o campo **Orientações** (texto livre, que aparece no fim do plano).

No modo texto **não há salvamento automático**: o texto só é guardado ao clicar em **Salvar rascunho** (ou **Salvar e publicar**).

**Atenção:** no modo texto a família vê exatamente as palavras do autor. Se você digitar calorias no texto, a família verá as calorias.

### Modo estruturado

Monte **refeições** (nome, horário opcional, observação). Cada refeição tem **opções** (até 5; a família escolhe uma) e cada opção tem **itens**.
Para cada item:

- digite o nome na busca para achar o alimento (tabela TACO e "Minha tabela");
- informe a quantidade em **gramas** ou em **medida caseira** (colher de sopa, concha, unidade...). A família vê a medida caseira, por exemplo "2 colheres de sopa de arroz";
- adicione **trocas** (substitutos) ao item: na família aparece "... ou ...".

No alto da tela ficam os **totais do dia** (energia, proteína, carboidrato, gordura, fibra), calculados com a **Opção 1 de cada refeição**.
Se o paciente tem meta de energia (seção Energia), o total é comparado com a meta. As calorias por opção aparecem só para a equipe; a família não vê números de calorias de planos estruturados.
Itens cujo alimento não tem algum valor na TACO aparecem para a equipe com o aviso "valor não disponível na TACO" (esse valor conta como zero no total).

O modo estruturado **salva sozinho a cada 30 segundos** quando há mudança (e ao clicar em **Salvar rascunho**). Um item sem alimento escolhido na lista não é salvo: o editor avisa onde ele está.

### Publicar, editar e encerrar

- **Salvar rascunho** guarda sem publicar. **Salvar e publicar** (ou **Publicar** na página do plano) torna o plano vigente para a família e para a equipe.
- Publicar um plano novo **substitui** o vigente, que fica no **Histórico** como "Substituído". Nada é apagado.
- Para alterar um plano publicado, use **Editar (novo rascunho a partir deste)**: o plano vigente continua valendo até você publicar o rascunho.
- **Encerrar plano** exige um motivo; o paciente fica sem plano vigente. O Histórico mostra autor, quem publicou, datas e motivo do encerramento.
- **Imprimir** (e **Imprimir com totais**, nos planos estruturados) abre uma versão própria para impressão. **Imprimir** (sem totais) não mostra nenhuma caloria e pode ser entregue à família. A família tem a sua em "Versão para imprimir".
- **Descartar rascunho** apaga apenas rascunhos.

### Modelos e trechos de texto

- **Modelos** (menu "Modelos"): planos prontos da equipe, em texto ou estruturados, sem dados de paciente. Use-os em "Começar de → Modelo". Para criar um a partir de um plano existente, use **Salvar como modelo** na página do plano.
- **Trechos de texto** (menu "Modelos" → botão "Trechos de texto"): frases curtas e nomeadas (por exemplo, orientações de hidratação). No editor, o seletor de trechos insere o texto com um clique no plano ou nas orientações.

### Alimentos e "Minha tabela"

O menu **Alimentos** lista a tabela TACO e os alimentos da casa. Os valores são sempre por 100 g.

- Os dados da **TACO** e as **medidas caseiras da POF** **não podem ser editados**. Só é possível criar alimentos e medidas **próprios** ("Minha tabela"), inclusive receitas da casa (informe os valores por 100 g da receita já preparada).
- Em qualquer alimento (TACO ou próprio) você pode acrescentar medidas caseiras próprias; as medidas próprias podem ser editadas e desativadas. As da POF aparecem com a origem "POF" e são somente leitura.

**Medidas caseiras: revisar.** As medidas vêm da tabela do IBGE, *Pesquisa de Orçamentos Familiares (POF) 2008-2009*, ligadas aos alimentos da TACO.
Algumas ligações são **aproximações** e a equipe deve revisá-las e, se preciso, acrescentar as suas em "Alimentos" (Minha tabela):

- feijão carioca usa as linhas do grupo "feijão" da POF;
- tapioca usa a linha "tapioca com coco";
- **não há medidas de massa cozida**, porque a TACO não tem macarrão cozido (use gramas ou cadastre uma receita própria).

## Exames laboratoriais

Os exames ficam na ficha de cada paciente, no cartão **Exames**. Só a médica vê e edita. As nutricionistas e a secretária **não veem** exames.

### Registrar uma coleta

Em **Nova coleta** informe a data e digite os valores dos exames. Cada coleta guarda os valores e, se quiser, o **laudo** (PDF, JPG ou PNG, até 10 MB).
O sistema classifica cada valor como **normal**, **limítrofe** ou **alterado**, de acordo com a idade e o sexo do paciente **na data da coleta**.
O **HOMA-IR** é calculado a partir da glicemia e da insulina e aparece **somente para a equipe**.

### Evolução e gráfico

Na página do paciente há uma tabela com todas as coletas e, para cada exame, um gráfico da evolução com as faixas de referência.

### Liberar para a família

A liberação é **por coleta**: use **Liberar para a família** na coleta. No portal a família vê o valor e uma frase simples ("dentro do esperado", "um pouco fora do esperado"...), **sem o laudo**.
Dá para retirar a liberação depois.

### Catálogo de exames (faixas de referência)

No menu **Catálogo de exames** (`/catalogo-exames`) ficam os exames disponíveis e suas faixas de referência. As faixas já vêm carregadas, mas aparecem como **"a confirmar"**
até a médica revisar: abra o exame, confira as faixas e altere o que for preciso e clique em **Salvar** (salvar com as faixas **alteradas** as confirma; salvar sem alterá-las, por exemplo só para desativar um exame, **não** confirma) ou use o botão **Confirmar faixas** para aceitá-las como estão. Também é possível editar as faixas e ativar ou desativar exames.
As fontes das faixas estão em `data/exams/SOURCES.md`. As faixas são apoio à decisão; a interpretação clínica é sempre da médica.

## Metas e área da criança

As metas são combinados simples entre a equipe e a família (ex.: "beber água", "comer uma fruta no lanche"). A paciente (ou a família) marca o que cumpriu e ganha estrelas e selos.

### Criar metas para uma paciente

Na ficha da paciente, no cartão **Metas**, use **Ver metas** (`/pacientes/{id}/metas`) e depois **Nova meta**. Informe o **Texto para a família** (3 a 160 caracteres), o emoji, a categoria, a frequência, o início (de até 2 anos atrás a até 1 ano à frente) e, se quiser, o término (a meta dura no máximo 5 anos).
Há também o campo **Texto para a criança (opcional)**, mostrado à criança no lugar do texto da família. **Editar**, **Encerrar** e **Excluir** ficam na lista de metas (**Excluir** pede confirmação: as estrelas da meta deixam de contar). A tela mostra o painel semanal (dias feitos/combinados em cada semana).

### Biblioteca de metas

No menu **Biblioteca de metas** (`/metas-modelo`; médica e nutricionistas) ficam as metas prontas. Use **Nova meta pronta** para criar as suas; ao criar uma meta para a paciente, escolha uma pronta em **Escolher da biblioteca** para preencher o formulário. Já vêm metas de exemplo carregadas pelo seed `goal_1_templates.sql`.

### Conta da criança

Em **Conta da criança** (botão na ficha, `/pacientes/{id}/acesso-crianca`) a médica cria uma conta própria para a criança entrar sozinha. Informe o **Nome de usuário** e clique em **Criar conta**: o sistema gera uma **senha provisória, mostrada uma única vez** (anote e entregue à família).
No primeiro login a criança é **obrigada a trocar a senha**. Na tela de login o campo se chama **E-mail ou usuário**; a criança digita o nome de usuário.
Na mesma tela dá para **Redefinir senha (gera nova provisória)**, **Desativar conta** e **Reativar conta**.
A opção **Responsáveis veem as marcações feitas pelo próprio paciente** define se os responsáveis enxergam o que a criança marcou sozinha.
Quando o paciente completa 18 anos, a conta da criança é convertida pela decisão **transferir** do fluxo de maioridade já existente. Se a conta estiver desativada, o sistema pede para **Reativar conta** na tela **Conta da criança** antes de transferir.
Se a médica redefinir a senha, a sessão que estiver aberta com a senha antiga é encerrada e a criança entra de novo com a provisória.

### Meta sigilosa

A meta **sigilosa** (os responsáveis não veem; só o paciente e a equipe) só pode ser criada quando o paciente **tem conta da criança**. Na lista ela aparece com o selo "sigilosa". Só a médica decide o sigilo.

### Portal e marcação

No portal, a família e a criança veem **Metas** (`/portal/p/{id}/metas`) e marcam os últimos 7 dias. Os botões **Semana anterior** e **Semana seguinte** (só no portal) mostram outras semanas; semanas anteriores só podem ser marcadas dentro dessa janela. A equipe **não marca** no lugar da paciente.

### Cartões educativos

No menu **Cartões educativos** (`/cartoes`; **somente a médica**) ficam textos curtos de orientação ligados às metas. A instalação traz **40 cartões em rascunho** (seed `goal_2_edu_cards.sql`).
**Nenhum aparece para a família antes de ser revisado e publicado.** Use **Revisar** para ler e ajustar o texto e depois **Publicar**; **Despublicar** retira o cartão do portal. **Novo cartão** cria os seus.

## Tratamento e medicamentos

Aqui a médica registra os medicamentos em uso de cada paciente, a família anota as doses tomadas e relata efeitos indesejados, e a médica imprime a receita. **O sistema nunca sugere, calcula nem avisa sobre dose: a decisão clínica é sempre sua.** A nutricionista e a secretária não veem medicamentos, e o login da criança também não.

### Criar a prescrição

Na ficha da paciente, abra **Tratamento** e use **Nova prescrição**. Informe o **medicamento**, a **via**, a **dose**, a **frequência**, a **orientação para a família**, o **início**, o **término** (se houver) e a **próxima reavaliação**. O campo de **observações internas** é só da equipe: a família nunca o vê.

### Alterar a dose, encerrar e excluir

- **Alterar dose** cria uma nova linha no histórico, com a data em que passa a valer e o motivo. Nada é apagado: cada mudança fica registrada.
- **Encerrar** termina o tratamento e o mantém no histórico.
- **Excluir** serve só para algo registrado por engano.

### Prescrição sigilosa

A marca **Sigilosa** só pode ser ligada para pacientes **menores de 18 anos** e só pela médica. Depois de ligada, ela **nunca some sozinha** (nem quando o paciente faz 18 anos ou a prescrição é editada): só a médica a desmarca, de propósito. Os responsáveis não veem nada dela: nem a prescrição, nem as doses, nem os relatos ligados a ela.

### Família registra as doses

Em cada prescrição há a opção **A família pode registrar doses dadas**. O cartão **Tratamento** do portal aparece sempre que há uma prescrição visível, ativa ou futura; os botões para marcar as doses dos **últimos 7 dias** só aparecem nas prescrições com essa opção ligada.

### Relatos de efeitos e "Ciente"

A família pode **relatar um efeito indesejado** (até 90 dias para trás); a tela mostra um aviso geral de que, em caso de urgência, a família deve procurar atendimento. Na tela inicial, a médica vê os **relatos ainda não vistos** e as **reavaliações vencidas ou a vencer nos próximos 7 dias**. Ao ler um relato, ela clica em **Ciente** e, se quiser, anota a gravidade e a conduta.

### Receita

Preencha o **cabeçalho** uma única vez em **Receita (cabeçalho)** (`/admin/receita`, médica administradora). Depois, na ficha, escolha os itens da receita e clique em **Imprimir**; na janela de impressão do navegador, escolha **Salvar como PDF** se preferir um arquivo. A assinatura é **à mão**. Receitas de **controle especial** (duas vias) **não** são cobertas.

### Atestados e declarações

Na ficha do paciente, cartão **Atestados** (só a médica): **comparecimento**, **acompanhante**, **afastamento** (1 a 90 dias) e
**aptidão para atividade física**. O texto é fixo (`app/Certificates/CertificateText.php`); a médica preenche data, horário, dias,
acompanhante e uma observação opcional. O **CID** só sai com a caixa de autorização marcada. O cabeçalho usa o nome,
a **especialidade**, o **registro** e o **RQE** do cadastro da médica (**Equipe**); o rodapé usa cidade, telefone e endereço de
**Receita (cabeçalho)**. Cada emissão fica gravada (o PDF sai sempre igual) e aparece na linha do tempo; não se apaga,
**anula-se** com motivo (o PDF passa a sair com a marca "ANULADO").

## Diário de hábitos

Aqui a equipe pede à família que anote hábitos do dia a dia (alimentação, água, sono, atividade, tempo de tela, peso e medidas), e a família registra pelo portal. **O diário apenas guarda o que foi anotado: não calcula, não compara com metas e não julga nada.**

### Criar um pedido de registro

Na ficha da paciente, abra o cartão **Diário** (`/pacientes/ID/diario`) e use **Novo pedido**. Escolha o **tipo** (o que registrar), os **dias da semana** (o botão **Todo dia** marca todos), o **início**, o **término** (se houver) e uma **orientação** curta, que a família vê junto do pedido. O tipo não muda depois que já há registros. **Encerrar** termina o pedido e mantém o que já foi anotado; **Excluir** só funciona se ainda não há registros.

A **nutricionista** só pode pedir **alimentação** e **água**. A médica pede qualquer tipo.

### Pedido sigiloso

A marca **Sigiloso** só vale para pacientes **menores de 18 anos** e só a médica pode ligá-la. Os responsáveis e a nutricionista não veem o pedido nem os registros dele; só o paciente e a médica.

### A grade da semana

A tela do diário mostra uma **grade da semana** (segunda a domingo), com uma linha por pedido e um quadradinho por dia, e botões para navegar entre as semanas. Cada quadradinho mostra um resumo do que a família anotou (por exemplo, quantidade de água ou horas de sono). O sinal **—** significa que o dia **foi pedido, mas nada foi anotado**. Quadradinho vazio quer dizer que o pedido não vale naquele dia.

Abaixo da grade, cada dia com registros mostra o que a família anotou e quem anotou. Nos dias com **alimentação**, as refeições aparecem **ao lado do plano alimentar** publicado naquele dia, para comparar com o olho.

### Comentar

Em cada registro a equipe pode **comentar** (até 500 caracteres) e depois editar ou excluir o próprio comentário. A família vê os comentários no portal.

### A família anota

No portal, o cartão **Diário** lista os pedidos de hoje. A família anota **o dia de hoje e até 7 dias para trás**, e pode corrigir ou remover um registro dentro desse prazo. Nos pedidos de alimentação dá para anexar uma **foto do prato** (só do prato, nunca de pessoas). Os comentários da equipe aparecem junto de cada registro.

### Peso e medidas

O pedido "pesar toda segunda" (tipo **Peso e medidas**) não tem formulário próprio: o botão **Pesar hoje** leva à tela de medidas já existente, e o valor entra na curva de crescimento como qualquer outra medida.

## Agenda

A agenda marca as consultas de cada profissional, avisa a família e lembra a profissional na véspera. A **nutricionista** só agenda e vê pacientes que já foram **liberados** para ela.

### Meus horários

Cada profissional monta a própria agenda em **Meus horários**: as **faixas da semana** (dia, hora de início e de fim), a **duração** da consulta e o **endereço de atendimento**. O botão **Usar horário padrão da clínica** preenche de uma vez: segunda a sexta, 07:30 às 11:30 e 12:30 às 20:00; sábado, 07:30 às 12:30; consultas de 30 minutos. Depois é possível ajustar o que for diferente. Preencha também o endereço, que aparece nos avisos.

### Fechamentos e horários extras

Um **fechamento** bloqueia um dia ou período (feriado, viagem, férias); a **secretária também pode cadastrar e remover** fechamentos. Um **horário extra** abre um horário fora das faixas da semana, só naquele dia; horários extras são lançados e removidos só pela própria profissional ou pela médica administradora.

### Marcar e acompanhar a consulta

Na tela da agenda, escolha o horário livre e a paciente para **marcar**. A consulta passa por estas situações: **Agendada**, **Confirmada**, **Realizada**, **Faltou**, **Cancelada** e **Remarcada** (a remarcação cria a nova consulta e guarda a data anterior). A ficha da paciente e o portal da família mostram **cartões de Consultas** com as próximas e as passadas.

Quando uma profissional é **desativada**, a agenda dela continua aparecendo para a secretária e para a médica administradora, como "(desativada)", enquanto houver consultas futuras: dá para cancelá-las ou remarcá-las para outra profissional, mas não para marcar novas. Essas consultas não geram lembrete da véspera.

### Aviso por WhatsApp

O botão do **WhatsApp** copia a mensagem e abre a conversa com o texto já pronto. Há **um botão para cada número disponível**: o **telefone da paciente** (ficha) e o **celular de cada responsável ativo** (cadastre-o na ficha). Basta conferir e enviar.

### E-mails

- **Para a família:** e-mails automáticos quando a consulta é marcada, remarcada ou cancelada (com texto fixo) e, na véspera às 08:00, o **lembrete** "Lembrete: consulta amanhã às HH:MM", com o mesmo texto do aviso.
- **Para a profissional:** na **véspera, às 08:00**, cada profissional recebe por e-mail as consultas do dia seguinte. Esse envio depende do **Cron de 5 minutos** (`cron/run.php`) na hospedagem.
- Os **textos** do aviso de WhatsApp (também usado no e-mail da véspera da profissional e no lembrete da família) são configurados pela médica administradora em `/admin/agenda`. Os e-mails de marcada, cancelada e remarcada têm redação fixa (só o endereço vem das configurações).

## Linha do tempo e meta de peso

Só a **médica** e a **nutricionista** (com o paciente liberado) usam estas telas. A família e a criança **nunca** veem a meta de peso nem a linha do tempo.

### Linha do tempo

- Na ficha do paciente, clique em **Linha do tempo**. Abre mostrando tudo **desde a última visita** (a última consulta da agenda marcada como *realizada* antes de hoje; sem consulta, a última evolução).
- Troque o período por **3 meses**, **12 meses** ou **Tudo**. Cada linha leva para a tela de origem.
- Cada pessoa vê só o que já pode ver: a nutricionista vê medições, planos, as consultas dela e a meta de peso; não vê remédios, exames, evoluções nem metas de hábito.

### Meta de peso

- Na ficha, cartão **Meta de peso** → **Definir meta**. É preciso ter uma medição (peso; na criança, peso e altura no mesmo dia), que vira o ponto de partida.
- **Adulto:** meta em kg (o IMC aparece junto). **Abaixo de 13 anos:** meta em escore-z do IMC (aparece também "≈ X kg com a altura atual"). **De 13 anos até 19:** quem registra escolhe kg ou escore-z. Na meta em escore-z, o prazo não pode passar do fim das curvas da OMS (19 anos e 1 mês).
- O gráfico mostra as medições, o **caminho combinado** (tracejado, da partida até o alvo no prazo) e, a partir da **5ª medição**, a **tendência**.
- Situação: **No caminho** (até 1 kg, ou 0,2 de escore-z, do caminho), **À frente**, **Atrás**, **Meta atingida** ou **Prazo vencido**. Medições feitas pela família contam e aparecem marcadas.
- **Trocar** a meta encerra a anterior (fica no histórico como "substituída"); **Encerrar meta** tira a meta ativa. Nada é apagado.

## Painel de evolução

Só a médica. Na ficha, cartão **Tratamento** ou **Meta de peso** → **Evolução** (ou pela Linha do tempo).
- Curva de **peso** (adulto) ou **escore-z do IMC** (até 19 anos e 1 mês) com as **faixas de dose** de cada remédio no mesmo calendário.
  Bolinha cheia = pesagem no consultório; vazia = em casa. Pesagem de criança sem altura usa a última altura dos 90 dias anteriores.
- **Ritmo por dose** (kg/semana ou queda de escore-z em 4 semanas) e o aviso **"Perda de peso estabilizou nesta dose"**, olhando as
  últimas 4 semanas (ou desde a última mudança de dose; são precisas 3 pesagens em 14 dias).
- **Critério por paciente** no fim da página: kg/semana (padrão 0,2) ou % do peso em 4 semanas (padrão 1%) para adulto; queda de
  escore-z em 4 semanas (padrão 0,05) para criança.
- **Metabolismo** (clique para abrir): cintura, pressão, glicemia, HbA1c, insulina, triglicerídeos, HDL e TGP, nas mesmas datas.

## Ditado por voz

Todo campo de texto longo do sistema (evolução, anamnese, observações, plano alimentar, diário…) e os campos curtos de texto livre (texto para a família e para a criança, títulos, motivos, orientações, observações do portal) têm o botão **🎤** ao lado (Chrome, Edge e Safari; no Firefox não aparece). Clique, fale e clique em **■** para parar; o texto entra no fim do campo e só é salvo pelo botão de salvar da tela. Campos de nome, busca, número, data, código e e-mail não têm microfone.
- Campo curto novo com texto livre: acrescente `data-ditado` ao `<input type="text">`. Para tirar o microfone de um `<textarea>`: `data-sem-ditado`.
- Pontuação falada: "vírgula", "ponto" / "ponto final", "dois pontos", "ponto e vírgula", "interrogação", "nova linha" / "parágrafo".
- O reconhecimento é do navegador: **o áudio vai para o Google (Chrome), a Microsoft (Edge) ou a Apple (Safari)**. O sistema não grava áudio. Revise o texto antes de salvar.
- Se o navegador perguntar, permita o microfone para `rubia.med.br`.
- Testes da parte de texto (só no desenvolvimento): `node --test "tests/js/*.test.js"`.

## Manual de utilização

Cada perfil tem o seu manual em **Ajuda** (menu do painel e topo do portal): médica, nutricionista, secretária, responsável,
paciente adulto e criança. Cada um vê só o próprio manual; a **médica administradora** abre os seis (links no topo da página)
para treinar a equipe e mandar o PDF às famílias antes do primeiro acesso. Cada manual tem o botão **Baixar PDF**.

- O conteúdo fica em `app/Help/content/<perfil>.php` (dados: seções com título, passos, dica e fotos). Seções comuns:
  `_equipe.php` (toda a equipe), `_clinica.php` (médica e nutricionista) e `_familia.php` (responsável e paciente).
  **O manual da nutricionista não pode citar medicamentos, exames, evolução nem receita** (teste `HelpManualTest`).
- **As fotos e os PDFs vêm de uma clínica fictícia local**, nunca de rubia.med.br. Todos os nomes são inventados e cada
  manual avisa: "Todos os nomes, pacientes e números deste manual são fictícios…".
- `public/assets/manual/` (fotos + PDFs) precisa ficar em até 8 MB (teste `HelpAssetsTest`).

Para refazer as fotos e os PDFs depois de mudar uma tela (máquina de desenvolvimento):

```
source tools/env.sh
# uma vez: mariadb-install-db.exe --datadir="$(cygpath -w "$PWD/storage/demo-db")" --port=3307
#          cp config/config.demo.example.php config/config.demo.php
start_demo_db                                                  # banco SEPARADO (porta 3307), só localhost
APP_ENV=demo php tools/demo/seed.php                           # recria a clínica fictícia (recusa outro banco)
APP_ENV=demo php -S 127.0.0.1:8001 -t public tools/demo/router.php   # em outro terminal
node tools/manual_shots.mjs                                    # todas as fotos (ou --only medica)
php tools/manual_pdf.php                                       # os seis PDFs
```

Nada disso vai no pacote: `tools/demo/`, `tools/manual_*` e `config/config.demo.php` ficam fora do zip.

## 6. Limites legais

- O sistema foi feito **seguindo os requisitos** da Resolução CFM 1.821/2007 e da LGPD: identificação do profissional,
  integridade, auditoria, controle de acesso, backup e guarda.
- Ele **não tem certificação SBIS/CFM** nem assinatura digital ICP-Brasil. Por isso **não substitui o prontuário em papel
  assinado**; como usar o sistema perante o CFM é decisão da Dra. Rubia.
- O sistema começa vazio (sem migração de dados). Registros antigos podem ser digitados com a data original e ficam marcados
  como "registro retroativo".
- Guarda do prontuário: **mínimo de 20 anos** a partir do último registro (Lei 13.787/2018). Não apague backups nem o banco antes disso.
- A Dra. Rubia é a **controladora** dos dados (LGPD); a hospedagem é a operadora. Vale conferir no contrato da hospedagem
  onde os dados ficam e como são protegidos.
- O sistema **não sugere nem ajusta doses de medicamentos**: apenas registra o que a médica decide.

## 7. Fontes dos dados

- **OMS 2006** (0 a 5 anos) e **OMS 2007** (5 a 19 anos): tabelas LMS oficiais dos pacotes `anthro` e `anthroplus` da OMS,
  em `data/who/`. Origem, commits e somas SHA-256 de cada arquivo estão em `data/who/SOURCES.md`.
- **AAP 2017** (pressão arterial de crianças e adolescentes): Flynn et al., *Pediatrics* 2017;140(3):e20171904, em `data/bp/`.
  Origem e SHA-256 em `data/bp/SOURCES.md`. Para adultos usam-se os limites habituais de pressão arterial.
- **TACO 4ª edição** (composição de alimentos, por 100 g): NEPA/UNICAMP, 2011, em `data/taco/`.
  Origem, commit e SHA-256 em `data/taco/SOURCES.md`.
- **POF 2008-2009** (medidas caseiras, em gramas): IBGE, *Tabela de Medidas Referidas para os Alimentos Consumidos no Brasil*, em `data/pof/`.
  Origem em `data/pof/SOURCES.md`. Algumas ligações com a TACO são aproximações (veja "Plano alimentar").
- **Gasto energético (TMB)**: Mifflin-St Jeor (1990), Harris-Benedict (1919, revisada em 1984) e FAO/OMS 1985 (Schofield).
  A fórmula usada e as metas ficam registradas para cada paciente.
- **IMC e circunferências em adultos**: classificação da OMS (IMC) e pontos de corte da OMS para cintura e relação cintura/quadril.

As curvas e os escores são apoio à decisão; a interpretação clínica é sempre da médica.

## 8. Desenvolvimento

Ambiente local (Windows, Git Bash):

```
source tools/env.sh        # php e mariadb no PATH
start_mariadb              # se o servidor não estiver rodando
cp config/config.example.php config/config.php        # e config/config.test.php (banco rubia_test)
php tools/phpunit.phar                                 # todas as suítes (Unit, Integration, Http)
php tools/phpunit.phar --testsuite Http                # uma suíte
php tools/phpunit.phar tests/Http/SmokeAllRoutesTest.php   # um arquivo
php -S 127.0.0.1:8000 -t public                        # servidor local
php tools/build_release.php                            # gera dist/rubia-release-AAAAMMDD.zip
```

Os testes recriam o banco `rubia_test` a cada execução; use um banco separado do de desenvolvimento.

## 9. Atualização de versão

1. Gere o novo pacote (`php tools/build_release.php`) e envie o `.zip` para `/home/USUARIO/rubia_app/`.
   **ATENÇÃO — extraia o zip DENTRO de `rubia_app`, nunca na pasta do site.**
   - Errado: extrair em `public_html/rubia.med.br/` (o site continua com o código antigo e o código do sistema fica exposto na internet).
   - Certo: extrair em `/home/USUARIO/rubia_app/` (em hospedagens com `/home2`, `/home2/USUARIO/rubia_app/`).
   - Conferência rápida: depois de extrair, confira que existe `rubia_app/app/Labs`. Se não existir, a extração foi para o lugar errado.
2. Extraia **por cima** dos arquivos existentes (opção de sobrescrever). O pacote não contém `config/config.php`, `config/.install.lock`
   nem a pasta de dados (`storage/` ou `rubia_storage/`), então esses itens **não são apagados** — nunca exclua a pasta `rubia_app` inteira
   nem o `config/` do servidor.
3. Se a versão trouxe mudanças em `public/` (visual, JavaScript), copie de novo o conteúdo de `rubia_app/public/` para a raiz do domínio (ex.: `public_html/rubia.med.br/`)
   e refaça o ajuste da linha `// AJUSTE NO DEPLOY` do `index.php`.
4. Se a versão trouxe novas tabelas ou dados de referência, faça um **backup** antes. Com terminal no cPanel, rode
   `php /home/USUARIO/rubia_app/tools/migrate.php`: aplica as migrações novas e recarrega os dados de referência, tudo na ordem certa.
   Sem terminal, importe no phpMyAdmin, **nesta ordem**: (1) `database/migrations/008_meal_plans.sql`, (2) `database/seeds/food_1_taco.sql`,
   (3) `database/seeds/food_2_pof_measures.sql`. A ordem importa: os alimentos precisam existir antes das medidas.
   Em versões futuras, importe as migrações novas (por número) e **depois** os arquivos de `database/seeds/`.
   Os seeds podem ser importados de novo sem duplicar dados. A atualização do plano alimentar (Fase 2a) traz a migração `008_meal_plans.sql`
   e os dois arquivos `food_*.sql`.
   **Não misture os dois caminhos.** O `tools/migrate.php` anota cada migração aplicada na tabela `schema_migrations`; se você importou a
   migração pelo phpMyAdmin, ela não fica anotada e um `migrate.php` rodado depois tentaria criá-la de novo (erro). Quem usou o phpMyAdmin
   deve, logo depois de importar a `008`, executar na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('008_meal_plans.sql');`
5. **A atualização do plano alimentar (Fase 2a) muda `public/`** (novos arquivos JavaScript e CSS do editor e do portal): o passo 3 é
   obrigatório nesta versão — copie de novo o conteúdo de `rubia_app/public/` para a raiz do domínio (ex.: `public_html/rubia.med.br/`).
6. **A atualização de exames laboratoriais (Fase 2b)** traz a migração `009_lab_exams.sql` e o seed `database/seeds/lab_catalog.sql` (catálogo e faixas).
   Faça **backup** antes. Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php` aplica os dois. Sem terminal, importe no phpMyAdmin,
   **nesta ordem**: (1) `database/migrations/009_lab_exams.sql`, (2) `database/seeds/lab_catalog.sql`, e logo depois, na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('009_lab_exams.sql');`
   O seed pode ser importado de novo sem duplicar dados. Esta versão **muda `public/`** (gráfico de exames): o passo 3 é obrigatório.
7. **Envio de laudos:** em cPanel → "Select PHP Version" → "Options", ajuste `upload_max_filesize` para **10M ou mais** e `post_max_size` para **pelo menos 32M** (e maior que `upload_max_filesize`, porque vários laudos podem ir juntos)
   (senão o envio de laudos grandes falha). A extensão `fileinfo` do PHP precisa estar ativa (o instalador confere).
8. **A atualização de metas e área da criança (Fase 3a)** traz a migração `010_goals.sql` e os seeds `database/seeds/goal_1_templates.sql` e `database/seeds/goal_2_edu_cards.sql`.
   Faça **backup** antes. Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php`. Sem terminal, importe no phpMyAdmin, **nesta ordem**:
   (1) `database/migrations/010_goals.sql`, (2) `database/seeds/goal_1_templates.sql`, (3) `database/seeds/goal_2_edu_cards.sql`, e logo depois, na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('010_goals.sql');`
   Extraia o zip **dentro de `rubia_app`** (ex.: `/home2/USUARIO/rubia_app/`), nunca na pasta do site. Esta versão **muda `public/`** (novo `goal-check.js` e CSS):
   a cópia do **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/` é **obrigatória**. Copie o conteúdo, nunca para `public_html/` em si (é outro site),
   e mantenha o `$APP_ROOT` do `index.php` apontando para `rubia_app`. Os 40 cartões educativos entram como rascunho; a médica os revisa e publica em **Cartões educativos**.
9. **A atualização de tratamento e medicamentos (Fase 3b)** traz a migração `011_medications.sql`. Não há seeds nesta versão.
   Faça **backup** antes. Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php`. Sem terminal, importe no phpMyAdmin
   `database/migrations/011_medications.sql` e logo depois, na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('011_medications.sql');`
   Extraia o zip **dentro de `rubia_app`** (ex.: `/home2/USUARIO/rubia_app/`), nunca na pasta do site. Esta versão **muda `public/`** (CSS):
   a cópia do **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/` é **obrigatória**. Copie o conteúdo, nunca para `public_html/` em si (é outro site),
   e mantenha o `$APP_ROOT` do `index.php` apontando para `rubia_app`. Depois de instalar, a médica administradora preenche o cabeçalho da receita em
   **Receita (cabeçalho)** (`/admin/receita`).
10. **A atualização do diário de hábitos (Fase 3c)** traz a migração `012_diary.sql`. Não há seeds nesta versão.
   Faça **backup do banco** antes. Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php`. Sem terminal, importe no phpMyAdmin
   `database/migrations/012_diary.sql` e logo depois, na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('012_diary.sql');`
   Extraia o zip **dentro de `rubia_app`** (ex.: `/home2/USUARIO/rubia_app/`), nunca na pasta do site. Esta versão **muda `public/`** (CSS e JavaScript):
   a cópia do **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/` é **obrigatória**. Copie o conteúdo, nunca para `public_html/` em si (é outro site).
   **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`** (`$APP_ROOT = '/home2/USUARIO/rubia_app';`), porque a cópia substitui o arquivo.
   **Fotos do prato:** para reduzir e endireitar as fotos (celulares gravam fotos "deitadas"), a hospedagem precisa das extensões **GD** e **exif** do PHP:
   cPanel → "Selecionar versão do PHP" → Extensões → marque `gd` e `exif`. Sem a `gd` o diário funciona normalmente, mas a foto é guardada **como foi enviada** (até 10 MB, com seus metadados). Com a `gd` e sem a `exif`, a foto é reduzida mas não é endireitada. Fotos muito grandes (acima de ~25 megapixels) também são guardadas como foram enviadas.
11. **A atualização da agenda (Fase 4a)** traz a migração `013_agenda.sql`. Não há seeds nesta versão.
   Requer MySQL 5.7 ou mais novo (ou MariaDB 10.2+); a versão aparece no phpMyAdmin, quadro "Servidor de banco de dados" → "Versão do servidor".
   Faça **backup do banco** antes. Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php`. Sem terminal, importe no phpMyAdmin
   `database/migrations/013_agenda.sql` e logo depois, na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('013_agenda.sql');`
   Extraia o zip **dentro de `rubia_app`** (ex.: `/home2/USUARIO/rubia_app/`), nunca na pasta do site. Esta versão **muda `public/`** (CSS e JavaScript, incluindo `agenda-whatsapp.js`):
   a cópia do **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/` é **obrigatória**. Copie o conteúdo, nunca para `public_html/` em si (é outro site).
   **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`** (`$APP_ROOT = '/home2/USUARIO/rubia_app';`), porque a cópia substitui o arquivo.
   **Cron:** confira que o Cron de 5 minutos (`cron/run.php`) existe no cPanel, pois o e-mail da véspera depende dele.
   **Depois de instalar:** cada profissional entra em **Meus horários** e clica em **Usar horário padrão da clínica** (ou monta os horários dela) e preenche o endereço de atendimento.
12. **A atualização da receita e do plano em PDF (Fase 4b)** não tem migração nem seeds. Ela traz a biblioteca **Dompdf 3.1.6** na pasta `lib/dompdf/` (vai dentro do zip; nada para instalar à parte).
   Requer a extensão **`dom`** do PHP (quase sempre já ativa): cPanel → "Selecionar versão do PHP" → Extensões → `dom` marcada. O verificador do instalador avisa se faltar.
   Extraia o zip **dentro de `rubia_app`** como nas outras versões. Esta versão **não muda `public/`**: não é preciso copiar nada para `public_html/rubia.med.br/`.
   O PDF guarda um cache de fontes em `cache/dompdf`, dentro da pasta de dados (criada sozinha). Para usar: na receita ou no plano publicado, abra **Imprimir** e clique em **Baixar PDF**.
13. **A atualização da linha do tempo e da meta de peso (Fase 4d)** traz a migração `014_weight_goals.sql`. Não há seeds nesta versão.
   Faça **backup do banco** antes. Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php`. Sem terminal, importe no phpMyAdmin
   `database/migrations/014_weight_goals.sql` e logo depois, na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('014_weight_goals.sql');`
   Extraia o zip **dentro de `rubia_app`**, nunca na pasta do site. Esta versão **muda `public/`** (novo `weight-goal-chart.js`):
   a cópia do **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/` é **obrigatória**. Copie o conteúdo, nunca para `public_html/` em si (é outro site).
   **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`** (`$APP_ROOT = '/home2/USUARIO/rubia_app';`), porque a cópia substitui o arquivo.
14. **A atualização do ditado por voz** não tem migração. Extraia o zip **dentro de `rubia_app`** e copie o **conteúdo** de `rubia_app/public/`
   para `public_html/rubia.med.br/` (novo `dictation.js` e CSS). **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`.**
15. **A atualização do painel de evolução** traz a migração `015_evolution_settings.sql`. Faça **backup do banco** antes.
   Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php`. Sem terminal, importe no phpMyAdmin
   `database/migrations/015_evolution_settings.sql` e logo depois, na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('015_evolution_settings.sql');`
   Extraia o zip **dentro de `rubia_app`** e copie o **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/`
   (novo `evolution-chart.js` e CSS). **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`.**
16. **A atualização do manual de utilização** não tem migração. Extraia o zip **dentro de `rubia_app`** e copie o **conteúdo** de
   `rubia_app/public/` para `public_html/rubia.med.br/` (nova pasta `assets/manual/` com fotos e PDFs, e CSS).
   **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`.**
17. **A atualização dos atestados** traz a migração `016_certificates.sql`. Faça **backup do banco** antes.
   Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php`. Sem terminal, importe no phpMyAdmin
   `database/migrations/016_certificates.sql` e logo depois, na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('016_certificates.sql');`
   Extraia o zip **dentro de `rubia_app`** e copie o **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/`
   (CSS e fotos novas do manual). **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`.**
   Depois, em **Equipe**, preencha a **especialidade** e o **RQE** da médica.
18. **A atualização das dietas prontas** traz a migração `017_diet_library.sql` e dois seeds: `database/seeds/food_1_taco.sql`
   (agora com as calorias do leite integral e do desnatado, que a TACO deixava em branco) e o novo `database/seeds/food_4_diet_library.sql`
   (1.664 dietas, cerca de 5 MB). Faça **backup do banco** antes. Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php` aplica tudo.
   Sem terminal, importe no phpMyAdmin, **nesta ordem**: (1) `database/migrations/017_diet_library.sql`, (2) na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('017_diet_library.sql');`, (3) `database/seeds/food_1_taco.sql`,
   (4) `database/seeds/food_4_diet_library.sql` (se o phpMyAdmin recusar pelo tamanho, use o terminal).
   Extraia o zip **dentro de `rubia_app`** e copie o **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/`
   (PDFs do manual da médica e da nutricionista). **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`.**
19. **A atualização do plano alimentar por dias** traz a migração `018_meal_plan_days.sql` e o seed `database/seeds/food_4_diet_library.sql`
   atualizado (cerca de 5 MB). Faça **backup do banco** antes. Com terminal: `php /home2/USUARIO/rubia_app/tools/migrate.php` aplica tudo.
   Sem terminal, importe no phpMyAdmin, **nesta ordem**: (1) `database/migrations/018_meal_plan_days.sql`, (2) na aba "SQL":
   `INSERT INTO schema_migrations (name) VALUES ('018_meal_plan_days.sql');`, (3) `database/seeds/food_4_diet_library.sql`
   (se o phpMyAdmin recusar pelo tamanho, use o terminal).
   Extraia o zip **dentro de `rubia_app`** e copie o **conteúdo** de `rubia_app/public/` para `public_html/rubia.med.br/`
   (inclui o novo `assets/js/plan-days.js`, o `meal-plan-editor.js` atualizado, CSS e os PDFs do manual da médica e da nutricionista).
   **Depois da cópia, refaça a linha `// AJUSTE NO DEPLOY` do `index.php`.**
   **Cron:** confira que o Cron de 5 minutos (`cron/run.php`) existe no cPanel, pois a continuação automática dos planos
   (rascunho e e-mail de aviso a partir das 06:00) depende dele.
