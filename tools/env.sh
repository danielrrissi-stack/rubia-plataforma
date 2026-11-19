# source tools/env.sh  -> php/mariadb no PATH (Git Bash) e helper para subir o MariaDB
export PATH="/c/Users/Projeto/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe:/c/Program Files/MariaDB 13.0/bin:$PATH"
# Sem servico Windows: se o MariaDB nao estiver rodando, execute: start_mariadb
start_mariadb() { (mariadbd.exe --console >/dev/null 2>&1 &) ; }
# Banco SEPARADO da clinica ficticia do manual (porta 3307, dados em storage/demo-db, so localhost).
# Criar uma vez: mariadb-install-db.exe --datadir="$(cygpath -w "$PWD/storage/demo-db")" --port=3307
start_demo_db() { (mariadbd.exe --defaults-file="$(cygpath -w "$PWD/storage/demo-db/my.ini")" --bind-address=127.0.0.1 --console >/dev/null 2>&1 &) ; }
