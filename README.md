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

Troque `USUARIO` pelo nome de usuário da sua conta no cPanel.

1. **Criar o banco de dados.** No cPanel, abra "Bancos de dados MySQL". Crie um banco (ex.: `USUARIO_rubia`),
   crie um usuário com senha forte (ex.: `USUARIO_rubia`) e adicione o usuário ao banco com "Todos os privilégios".
   Anote o nome do banco, o usuário e a senha.
2. **Enviar o pacote.** Gere o pacote no computador de desenvolvimento com `php tools/build_release.php`
   (cria `dist/rubia-release-AAAAMMDD.zip`). No "Gerenciador de arquivos" do cPanel, crie a pasta
   `/home/USUARIO/rubia_app/`, envie o `.zip` para ela e use "Extrair". A pasta `rubia_app` fica **fora** de `public_html`.
3. **Publicar a parte pública.** Copie **o conteúdo** da pasta `rubia_app/public/` (incluindo o arquivo oculto `.htaccess`,
   a pasta `assets`, `index.php`, `manifest.json` e `sw.js`) para `public_html/`.
   Se `.htaccess` não aparecer, em "Configurações" do Gerenciador de arquivos marque "Mostrar arquivos ocultos".
4. **Ajustar o caminho.** Abra `public_html/index.php` no editor do cPanel e procure a linha marcada com `// AJUSTE NO DEPLOY`:

   ```php
   $APP_ROOT = dirname(__DIR__); // AJUSTE NO DEPLOY
   ```

   Troque por:

   ```php
   $APP_ROOT = '/home/USUARIO/rubia_app'; // AJUSTE NO DEPLOY
   ```

5. **Ativar o SSL.** No cPanel, em "SSL/TLS Status", rode o AutoSSL para o domínio e espere o certificado ficar ativo.
   O sistema só deve ser usado por `https://`: o `.htaccess` sempre redireciona `http://` para `https://`
   (exceto em `localhost`, para desenvolvimento) e envia o cabeçalho HSTS. Não há opção para desligar isso.
6. **Instalar, logo em seguida.** Abra `https://rubia.med.br/instalar` **imediatamente** depois de enviar os arquivos:
   a página de instalação não pede senha, então quem chegar primeiro configura o sistema. Preencha banco, endereço do site,
   e-mail de envio, os dados e a senha da médica administradora e a **senha de backup** (mínimo de 12 caracteres).
   O instalador cria as tabelas, carrega sozinho as tabelas de referência (curvas da OMS e pressão arterial da AAP),
   grava `config/config.php` e trava a instalação (arquivo `config/.install.lock`).
   Depois disso, `/instalar` passa a mostrar "página não encontrada".
   Os dados enviados pelo sistema (backups, fotos, registros) ficam em `/home/USUARIO/rubia_storage/`
   (fora do site) ou, se isso não for possível, em `rubia_app/storage/` protegida por `.htaccess`.
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
| Nutricionista | Somente pacientes liberados pela médica: cadastro básico, medidas e curvas, metas de energia e nutrição. Não vê prontuário. |
| Secretária | Cadastro e agenda. Não vê dados clínicos (medidas, prontuário). |
| Responsável | Somente os filhos vinculados: crescimento e registro de peso/altura no portal da família. |
| Paciente adulto | Somente o próprio cadastro básico e medidas: crescimento e registro de peso no portal. |

Toda consulta e alteração de dados de pacientes fica registrada (quem, quando, o quê).
Registros clínicos nunca são apagados: correções criam nova versão e a anterior é mantida.

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
3. Se a versão trouxe mudanças em `public/` (visual, JavaScript), copie de novo o conteúdo de `rubia_app/public/` para `public_html/`
   e refaça o ajuste da linha `// AJUSTE NO DEPLOY` do `index.php`.
4. Se a versão trouxe novas tabelas, elas estão em `database/migrations/`; aplique-as com `php /home/USUARIO/rubia_app/tools/migrate.php`
   (se o cPanel tiver terminal) ou importe os arquivos novos pelo phpMyAdmin. Faça um backup antes de atualizar.
