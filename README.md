# ServicePlace — API

API em PHP 8.5 (FPM) + Nginx, com SQLite. Roda em containers Docker.

## Estrutura

```
.
├── data/                 # SQLite (platform.db) — criado no setup, não versionado
⢜── docker/
�   ├── docker-compose.yaml
�   z├── dockerfile
�   z├── nginx.conf
�   z└── php-dev.ini
ⴜ── index.php            # front controller (roteador + API)
```

## Pré-requisitos

- Docker + Docker Compose (v2)
- Linux/macOS com shell POSI

## Setup inicial (uma vez só)
O container roda o PHP-FPM como `www-data` (uid **82**). A pasta `data/` precisa
ser gravável por esse uid, senão o SQLite falha com
`attempt to write a readonly database`.

Na raiz do repositório:
```sh
mkdir -p data
sudo chown -R 82:82 data
chmod 700 data
```

Verifique:
```sh
ls -ld data
# esperado: drwx------ ... 82 82 ... data
```

Se quiser inspecionar o banco pelo host depois, rode como root
(`sudo sqlite3 data/platform.db`) ou ajuste as permissões (veja "Inspecionar o banco").

## Rodar em desenvolvimento
Na raiz do repositório:

```sh
docker compose -f docker/docker-compose.yaml up --build
```

- `--build` recompila a imagem PHP (necessário na primeira vez e após mudanças
  no `Dockerfile` ou `php-dev.ini`)
- sem `-d` os logs aparecem no terminal (recomendado em dev)
- para rodar em background, adicione `-d` no final

A API fica em **http://localhost:8080**.

Para parar: `Ctrl+C` (ou `docker compose -f docker/docker-compose.yaml down`).

### Alternativa com Makefile (opcional)

Se preferir comandos curtos, crie um `Makefile` na raiz:

```make
COMPOSE := docker compose -f docker/docker-compose.yaml --project-directory docker

up:      ; $(COMPOSE) up --build
upd:     ; $(COMPOSE) up -d --build
down:    ; $(COMPOSE) down
logs:    ; $(COMPOSE) logs -f
ps:      ; $(COMPOSE) ps
```

Adé é só `make up`.

## Testar a API
Registrar um cliente:

```sh
curl -i http://localhost:8080/auth/register/client \
  -H "Content-Type: application/json" \
  -d '{"name":"Teste","email":"teste@test.com","password":"123"}'
```

Login:
```sh
curl -i http://localhost:8080/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"teste@test.com","password":"123"}'
```

Retorna um JWT. Use em rotas protegidas:

```sh
curl http://localhost:8080/me \
  -H "Authorization: Bearer <TOKEN>"
```

Listar fornecedores (público):

```sh
curl 'http://localhost:8080/providers?city=São Paulo'
```

## Variáveis de ambiente
Definidas no `docker/docker-compose.yaml` (ou via shell / `docker/.env`):
| Variável       | Default                                  | Descrição                    |
|----------------|-------------------------------------------|------------------------------|
| `JWT_SECRET`   | `dev-secret-troque-em-producao`         | Segredo HS256 para os tokens |

Em produção, **sempre** defina `JWT_SECRET` explicitamente:

```sh
JWT_SECRET="$(openssl rand -hex 32)" \
  docker compose -f docker/docker-compose.yaml up -d
```

## Trocar SQLite por MySQL
O código já suporta os dois drivers. No `docker/docker-compose.yaml`,
descomente o servi�o `mysql` (e o volume `mysql_data`). Depois edite
`index.php`:

```php
define('DB_DRIVER', 'mysql');
define('DB_DSN',    'mysql:host=mysql;dbname=platform;charset=utf8mb4');
define('DB_USER',   'platform');
define('DB_PASS',   'platform');
```

Recrie os containers:
```sh
docker compose -f docker/docker-compose.yaml up -d --build
```

## Inspecionar o banco
O `data/` é `700` e pertence ao uid 82. Para abrir o banco pelo host:

```sh
sudo sqlite3 data/platform.db
```

Se preferir acesso sem `sudo`, adicione seu usuário ao grupo 82 e libere o grupo:

```sh
sudo usermod -aG 82 "$USER"     # requer novo login/terminal
sudo chmod 770 data
```

## Resetar o banco
Como as migrations rodam na primeira requisição, apagar o `.db` força
recriação no próximo request:

```sh
sudo rm -f data/platform.db
curl http://localhost:8080/providers     # dispara as migrations
```

## Troubleshooting

**`could not connect to server` em `localhost:80`**
Nginx está publicado em `8080`, não em `80`. Use `http://localhost:8080`.

**`File not found.` (nginx) ou `POST /index.php 404` (FPM)**
O `index.php` não está visível dentro do container. Confira:

```sh
docker compose -f docker/docker-compose.yaml exec php ls -la /var/www/html
```

Se estiver vazio, há um volume montado em cima de `/var/www/html` no
`docker-compose.yaml` — remova-o. O bind mount `../:/var/www/html` é o único que deve existir.

**`SQLSTATE[HY000] [14] unable to open database file`**
A pasta `data/` não existe ou não é gravável pelo uid 82. Rode o setup inicial.

**`SQLSTATE[HY000]: General error: 8 attempt to write a readonly database`**
Mesma causa: permissões em `data/`. O SQLite precisa criar `platform.db-journal`
na mesma pasta, então **a pasta** precisa ser gravável, não só o arquivo:

```sh
sudo chown -R 82:82 data
chmod 700 data
```

**`nginx: [emerg] location "/index.php" is outside location "\.php$"`**
Config inválida: não se pode aninhar `location =` dentro de `location ~`
Mantenha o `if ($uri !~ ^/index\.php$) { return 403; }` (versão atual do
`nginx.conf`).

**`$setup-readme.sh` quebrou tags**
Edite o conteúdo em um arquivo texto editor (vim, nano, VS Code) e não cole
diretamente no terminal. O script usa `base64` e n�o passa por expansão de shell.

@ fim
