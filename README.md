# Rubia - acompanhamento nutricional

Sistema web (PHP + MySQL) para o acompanhamento de obesidade infantil e nutrição de adultos da Dra. Rubia.
Funciona em hospedagem compartilhada com cPanel, sem Composer e sem acesso por terminal.

## 1. Requisitos

- Hospedagem com cPanel, PHP 8.1 ou superior (recomendado 8.3) e MySQL/MariaDB.
- Extensões do PHP: `pdo_mysql`, `openssl`, `zip`, `mbstring`, `json` (o instalador confere e avisa).
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

- `TÍTULO:` sozinho numa linha: a linha seguinte vira o título de uma refeição (ex.: `TÍTULO:` e, embaixo, `Café da manhã`).
- `•` no começo da linha (também `-`, `*` ou `1.`): vira item de lista. Textos copiados do Word ou do PDF costumam funcionar.
- `**texto**`: negrito.
- Uma linha em branco separa parágrafos.

Há também o campo **Orientações** (texto livre, que aparece no fim do plano).

**Atenção:** no modo texto a família vê exatamente as palavras do autor. Se você digitar calorias no texto, a família verá as calorias.

### Modo estruturado

Monte **refeições** (nome, horário opcional, observação). Cada refeição tem **opções** (até 5; a família escolhe uma) e cada opção tem **itens**.
Para cada item:

- digite o nome na busca para achar o alimento (tabela TACO e "Minha tabela");
- informe a quantidade em **gramas** ou em **medida caseira** (colher de sopa, concha, unidade...). A família vê a medida caseira, por exemplo "2 colheres de sopa de arroz";
- adicione **trocas** (substitutos) ao item: na família aparece "... ou ...".

No alto da tela ficam os **totais do dia** (energia, proteína, carboidrato, gordura, fibra), calculados com a **Opção 1 de cada refeição**.
Se o paciente tem meta de energia (seção Energia), o total é comparado com a meta. As calorias por opção aparecem só para a equipe; a família não vê números de calorias de planos estruturados.

### Publicar, editar e encerrar

- **Salvar rascunho** guarda sem publicar. **Salvar e publicar** (ou **Publicar** na página do plano) torna o plano vigente para a família e para a equipe.
- Publicar um plano novo **substitui** o vigente, que fica no **Histórico** como "Substituído". Nada é apagado.
- Para alterar um plano publicado, use **Editar (novo rascunho a partir deste)**: o plano vigente continua valendo até você publicar o rascunho.
- **Encerrar plano** exige um motivo; o paciente fica sem plano vigente. O Histórico mostra autor, quem publicou, datas e motivo do encerramento.
- **Imprimir** (e **Imprimir com totais**, nos planos estruturados) abre uma versão própria para impressão. A família tem a sua em "Versão para imprimir".
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
