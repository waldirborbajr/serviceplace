# ServicePlace — API

API em PHP 8.5 (FPM) + Nginx, com SQLite. Roda em containers Docker.

## Estrutura

```
.
├── data/                 # SQLite (platform.db) — criado no setup, não versionado
├── docker/
│   ├── docker-compose.yaml
│   ├── Dockerfile
│   ├── nginx.conf
│   └── php-dev.ini
├── src/
│   ├── Core/
│   │   ├── Authorization.php
│   │   ├── Configuration.php
│   │   ├── Database.php
│   │   ├── Http.php
│   │   ├── Migrations.php
│   │   ├── Security.php
│   │   └── Validation.php
│   ├── Routes/           # registro das rotas por domínio
│   │   ├── auth.php
│   │   ├── events.php
│   │   ├── matchmaking.php
│   │   ├── payments.php
│   │   ├── provider-events.php
│   │   └── providers.php
│   ├── Services/         # handlers e regras de cada serviço
│   │   ├── AuthService.php
│   │   ├── EventService.php
│   │   ├── MatchmakingService.php
│   │   ├── PaymentService.php
│   │   └── ProviderService.php
│   └── bootstrap.php     # carrega módulos, migrations e rotas
└── index.php             # front controller e fallback 404
```

## Pré-requisitos

- Docker + Docker Compose (v2)
- Linux/macOS com shell POSI

## Setup inicial

O Makefile cria `data/` e configura as permissões necessárias para o SQLite
ser gravável pelo PHP-FPM (`www-data`, uid **82**). Na raiz do repositório,
execute uma vez:

```sh
make setup
```

Esse alvo usa `sudo` para ajustar o proprietário da pasta. Se as permissões
forem alteradas posteriormente, rode `make setup` novamente.

## Rodar em desenvolvimento
Após o setup, suba os containers:

```sh
make up
```

O alvo compila a imagem e mantém os logs no terminal. Outros comandos úteis:

```sh
make upd       # sobe em background
make logs      # acompanha os logs
make ps        # mostra o status dos containers
make down      # para e remove os containers
make restart   # reinicia os containers
```

A API fica em **http://localhost:8080**. Para recompilar após mudanças no
`Dockerfile` ou em `php-dev.ini`, rode `make up` ou `make upd` novamente.

### Outros alvos do Makefile

O Makefile também oferece comandos para inicializar dados de desenvolvimento,
validar a API e operar o banco:

```sh
make seed       # dispara migrations e seed (API deve estar rodando)
make health     # testa o login do usuário de teste
make health-all # testa o login de todos os usuários do seed
make token      # imprime um JWT de teste
make pay-test   # smoke test do fluxo de pagamento
make reset-rate # limpa o rate limit do login
make reset-db   # apaga o banco SQLite local
make rehealth   # reseta o banco, roda seed e testa o login
make help       # lista os alvos disponíveis
```

`make reset-db` apaga os dados locais. Depois, com a API em execução, use
`make seed` para recriar o banco e os dados de desenvolvimento.

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
`src/Core/Configuration.php`:

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

## Resetar o banco
Para apagar o banco local, use o alvo do Makefile:

```sh
make reset-db
make seed
```

As migrations e o seed são executados na primeira requisição após o reset.

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
A pasta `data/` não existe ou não é gravável pelo uid 82. Rode `make setup`.

**`SQLSTATE[HY000]: General error: 8 attempt to write a readonly database`**
Mesma causa: permissões em `data/`. O SQLite precisa criar arquivos auxiliares
na mesma pasta; rode `make setup` para ajustar as permissões.

**`nginx: [emerg] location "/index.php" is outside location "\.php$"`**
Config inválida: não se pode aninhar `location =` dentro de `location ~`
Mantenha o `if ($uri !~ ^/index\.php$) { return 403; }` (versão atual do
`nginx.conf`).

**`$setup-readme.sh` quebrou tags**
Edite o conteúdo em um arquivo texto editor (vim, nano, VS Code) e não cole
diretamente no terminal. O script usa `base64` e n�o passa por expansão de shell.

@ fim
