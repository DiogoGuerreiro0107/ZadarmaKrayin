# Integração Zadarma para o Krayin CRM — Instruções de Arranque

> Documento de referência para iniciar o desenvolvimento com o **Claude Code** neste projeto. É um projeto **separado** do OpenCRM (`C:\Coding\OpenCRM`) — ambos podem ser desenvolvidos em paralelo. Este ficheiro deve ficar na raiz do repositório como `CLAUDE.md`.

---

## 1. Visão geral

Extensão (package Laravel) para o [Krayin CRM](https://github.com/krayin/laravel-crm) que replica, dentro do Krayin, a integração de telefonia Zadarma já construída no OpenCRM: click-to-call a partir de um número (com confirmação), histórico de chamadas e acesso às gravações.

**Motivação:** o Krayin não tem integração oficial com a Zadarma — só uma extensão paga genérica de VoIP ($4.500, fornecedor não identificado, sem código aberto). Esta é a nossa própria versão, open-source, específica para a Zadarma.

**Diferença central face à versão que já existe no OpenCRM:** o OpenCRM corre exclusivamente em rede local (sem exposição à internet), pelo que a sincronização do histórico de chamadas foi feita por **polling periódico**. Este projeto **tem de suportar os dois modos**, porque uma instalação de Krayin pode legitimamente correr de qualquer uma das duas formas:

- **Modo exposto à internet** → usar **webhooks** da Zadarma (tempo real, mais eficiente).
- **Modo servidor local** (sem IP/domínio público) → usar **polling periódico** (mesma abordagem do OpenCRM).

O modo é uma opção de configuração explícita (não deteção automática) — ver secção 5.

---

## 2. Porque é um projeto separado do OpenCRM

- Stacks incompatíveis: o Krayin é Laravel (PHP) + Vue.js; o OpenCRM é NestJS/TypeScript + React. Não há código a partilhar diretamente, só a lógica/conhecimento (assinatura HMAC, endpoints da Zadarma, normalização de números de telefone), que é transferível conceptualmente mas tem de ser reescrita na stack do Krayin.
- É um package/extensão a instalar sobre uma instalação existente do Krayin, não uma aplicação nova — o "produto" final é um package Composer instalável (`packages/Webkul/Zadarma/` dentro de uma instalação Krayin, ou publicado como package Composer separado mais tarde).
- Podem evoluir a ritmos diferentes e ser usados em contextos diferentes (o OpenCRM é interno à Globaltoner; esta integração pode, no limite, ser reutilizada por qualquer instalação Krayin de terceiros).

---

## 3. Requisito principal: suportar os dois modos de rede

Esta é a exigência que **desenha toda a arquitetura** — implementar primeiro, não deixar para o fim.

### Configuração

- Variável de ambiente `ZADARMA_SYNC_MODE=webhook|polling` no `.env` do Krayin (default recomendado: `polling`, por ser seguro por omissão mesmo que o administrador se esqueça de configurar).
- Espelhada numa opção na página de configuração do package (secção 6) para poder ser vista/alterada pela UI, mas o valor efetivo lido pelo código vem sempre do `.env`/`config()` — nunca só da base de dados, para evitar o registo acidental de uma rota pública (`webhook`) num servidor que na verdade só está acessível localmente.
- Ambos os modos partilham a mesma lógica de normalização/idempotência de `CallRecord` (ver secção 5) — só a forma como uma chamada é **descoberta** difere (empurrada pela Zadarma vs. pedida por nós).

### Modo `webhook` (instalação exposta à internet)

- Regista uma rota pública (fora do grupo de middleware `admin`/`web` autenticado) para receber os eventos da Zadarma: `NOTIFY_START`, `NOTIFY_INTERNAL`, `NOTIFY_END`, `NOTIFY_RECORD` (nomes a confirmar na documentação oficial da Zadarma em [zadarma.com/en/support/api/](https://zadarma.com/en/support/api/) no momento da implementação — a Zadarma pode ter alterado nomes/parâmetros desde o treino deste modelo).
- **Segurança da rota pública é obrigatória, não opcional** — este projeto, ao contrário do OpenCRM, pode estar exposto à internet:
  - Validar a assinatura do pedido recebido da Zadarma (a Zadarma assina os webhooks; confirmar o mecanismo exato na documentação, é provável que seja semelhante ao HMAC dos pedidos de saída).
  - Rate limiting na rota (`throttle` middleware do Laravel).
  - Idealmente também um allowlist de IPs de origem da Zadarma, se a documentação deles publicar os ranges.
  - Nunca confiar em `disposition`/`recording_url` sem validar a origem — um endpoint de webhook mal protegido é uma forma de injetar registos de chamadas falsos.
- Regista o URL do webhook na conta Zadarma (manual, feito uma vez pelo utilizador no painel da Zadarma, ou via chamada à API deles se existir um endpoint para isso — confirmar).

### Modo `polling` (instalação só em rede local)

- Comando Artisan agendado (`php artisan zadarma:sync-calls`), registado no scheduler do Laravel (`app/Console/Kernel.php`, `$schedule->command(...)->everyTenMinutes()`), que chama `/v1/statistics/` desde o último `synced_at` guardado.
- Mesma lógica já validada no OpenCRM: HMAC (ordenar parâmetros, `md5` da query string, `hmac-sha1` de `caminho+query+md5params` com o `apiSecret`, `base64`, header `Authorization: "{apiKey}:{assinatura}"`) — replicar em PHP.
- Upsert por `external_id` único, exatamente como no OpenCRM, para que a sincronização seja idempotente mesmo que o intervalo pedido se sobreponha ao anterior.

---

## 4. Stack

| Camada | Tecnologia |
|---|---|
| Linguagem/Framework | PHP + Laravel (versão exigida pela instalação Krayin alvo — confirmar `composer.json` do Krayin) |
| Estrutura do package | Convenções do próprio Krayin (ver secção 5) — não inventar uma estrutura própria |
| Frontend | Vue.js, dentro do admin já existente do Krayin (não é uma app à parte) |
| Base de dados | A mesma do Krayin (MySQL/MariaDB tipicamente), via Eloquent + migrations do package |
| Agendamento (modo polling) | Laravel Task Scheduling (`Kernel.php`) |
| HTTP para a API da Zadarma | Guzzle (já vem com o Laravel) |

---

## 5. Arquitetura do package (convenções do Krayin — confirmar ao abrir o código)

O Krayin segue o padrão de packages "Webkul" (o mesmo usado no Bagisto, da mesma empresa). Estrutura esperada:

```
packages/Webkul/Zadarma/
├── src/
│   ├── Providers/
│   │   └── ZadarmaServiceProvider.php   # regista rotas, migrations, config, views, menu admin
│   ├── Http/
│   │   └── Controllers/
│   │       ├── SettingsController.php    # guardar apiKey/apiSecret/modo/extensão
│   │       ├── CallController.php        # POST click-to-call
│   │       └── WebhookController.php     # só ativo em modo webhook
│   ├── Console/
│   │   └── Commands/
│   │       └── SyncCallsCommand.php      # só relevante em modo polling
│   ├── Models/
│   │   └── CallRecord.php
│   ├── Services/
│   │   └── ZadarmaClient.php             # assinatura HMAC + chamadas à API
│   ├── Database/Migrations/
│   │   └── ..._create_call_records_table.php
│   ├── Config/
│   │   ├── system.php     # entra na página de Configuração do admin Krayin automaticamente
│   │   ├── acl.php         # permissões (quem pode ver/gerir a integração)
│   │   └── admin-menu.php  # item de menu em Configurações
│   ├── Routes/
│   │   └── routes.php      # grupo admin (autenticado) + grupo público (só webhook)
│   └── Resources/
│       ├── views/          # blade, se necessário
│       └── assets/         # componente Vue do botão de ligar + histórico
└── composer.json
```

- Registar o namespace `Webkul\Zadarma\` no `composer.json` raiz da instalação Krayin (`psr-4`), e o `ZadarmaServiceProvider` em `config/app.php` — seguir exatamente o padrão de um package existente do Krayin (ex.: o package `Lead` ou `Email` já incluído), **lendo o código-fonte real deles primeiro**, em vez de assumir que a estrutura acima está 100% certa. Esta secção é um ponto de partida, não uma verdade absoluta — os detalhes exatos (nomes de métodos do service provider, exact array shape do `system.php`) têm de ser confirmados no código do Krayin instalado, que pode ter mudado entre versões.
- `Config/system.php` é o mecanismo do Krayin (herdado do Bagisto) para criar automaticamente uma secção na página de Configuração do admin sem construir uma página de raiz — preferir isto a uma página de definições nossa, para consistência com o resto da app.

### Modelo de dados

```
zadarma_accounts (linha única, tal como no OpenCRM)
  - api_key, api_secret (nunca expor via API/UI depois de guardado — só "hasCredentials")
  - caller_extension
  - sync_mode (webhook|polling) — espelha o .env, só para mostrar na UI
  - active
  - last_synced_at

call_records
  - external_id (único — idempotência)
  - direction (inbound|outbound)
  - from_number, to_number
  - duration
  - disposition
  - recording_url
  - lead_id / contact_id / person_id (conforme o modelo de dados do Krayin — confirmar nomes exatos: "Person" ou "Contact"?)
  - started_at
```

---

## 6. Funcionalidades (mesmo âmbito do OpenCRM, adaptado à UI do Krayin)

1. **Configurações** (Configuração > Zadarma, via `Config/system.php`): API key/secret (guardar em branco = manter atual), extensão/número do utilizador, modo de sincronização (informativo — o real vem do `.env`), ativar/desativar.
2. **Botão de ligar**: ícone junto aos campos de telefone nas fichas de Lead/Contacto/Organização do Krayin, com confirmação antes de chamar `POST /v1/request/callback/` (mesma UX do OpenCRM — toca primeiro na extensão do utilizador, depois liga automaticamente ao número).
3. **Histórico de chamadas**: secção na ficha de Lead/Contacto/Organização listando `call_records` associados (correspondência por número de telefone, tolerante a formatação — reaproveitar a mesma lógica de "últimos 9 dígitos" já validada no OpenCRM).
4. **Sincronização**: conforme o modo escolhido (secção 3).

---

## 7. Roadmap sugerido

| Fase | Objetivo |
|---|---|
| 0 | Instalação de uma instância Krayin local para desenvolvimento; ler o código-fonte de 1-2 packages existentes (ex.: `Lead`, `Email`) para confirmar as convenções reais de service provider/rotas/migrations/config antes de escrever qualquer código próprio |
| 1 | Esqueleto do package (`ZadarmaServiceProvider`, registo no `composer.json`/`config/app.php`, migrations `zadarma_accounts`/`call_records`) |
| 2 | `ZadarmaClient` (assinatura HMAC) + página de Configurações (`Config/system.php`) — sem ligar nada ainda, só guardar/ler credenciais |
| 3 | Modo `polling`: `SyncCallsCommand` + agendamento no `Kernel.php` |
| 4 | Modo `webhook`: rota pública protegida + validação de origem/assinatura + registo do URL na conta Zadarma |
| 5 | Botão de ligar (click-to-call) na UI Vue das fichas de Lead/Contacto/Organização |
| 6 | Secção de histórico de chamadas nas mesmas fichas |
| 7 | Validar os dois modos (idealmente com uma conta Zadarma de testes real, já que este projeto — ao contrário da build inicial do OpenCRM — pode e deve ser testado em modo `webhook` com um túnel público, ex. `ngrok`, durante o desenvolvimento) |

**Recomendação igual à do OpenCRM:** implementar uma fase de cada vez, validando antes de avançar.

---

## 8. Coisas a verificar antes/durante a implementação (não assumir)

- Nomes exatos dos eventos/parâmetros de webhook da Zadarma — confirmar em [zadarma.com/en/support/api/](https://zadarma.com/en/support/api/) no momento da implementação.
- Se a Zadarma assina os webhooks de saída (deles para nós) da mesma forma que assina os pedidos que nós fazemos, ou se o mecanismo de validação é diferente.
- Se existe já algum mecanismo genérico de "webhook público" no Krayin (ex.: para o WhatsApp) que possa ser reaproveitado em vez de construído de raiz.

### 8.1 Confirmado na Fase 0 (2026-07-03, lendo `krayin/laravel-crm` real, tag atual em `krayin-app/`)

Correções às suposições das secções 3-6 acima, confirmadas lendo o código-fonte real dos packages `Lead`, `Contact`, `Email` e `Admin`:

- **Versão**: Laravel `^12.0`, PHP `^8.3`. Laravel 12 já não usa `app/Console/Kernel.php` para o scheduler — usa `routes/console.php` com `Schedule::command(...)`. Como não podemos editar esse ficheiro da app a partir de um package, o nosso `ZadarmaServiceProvider` deve registar o agendamento via `$this->app->afterResolving(Schedule::class, fn ($schedule) => $schedule->command('zadarma:sync-calls')->everyTenMinutes());` dentro do `boot()` — é o padrão correto para packages em Laravel 11+, não o `Kernel.php` mencionado na secção 3.
- **Entidade "pessoa"**: chama-se `Person` (e `Organization`), ambas dentro do package `Webkul\Contact` (`packages/Webkul/Contact/src/Models/Person.php`). Não existe um modelo "Contact" — esse é o nome do *package*. `Lead` tem `person_id` como FK. **Não existe** um `Contact` model — corrigir qualquer referência a "contact_id" na secção 5 para `person_id`.
- **Números de telefone**: `persons.contact_numbers` é uma coluna `json` (cast `array` no Eloquent), não uma string simples — pode conter vários números. A correspondência por "últimos 9 dígitos" (secção 6.3) tem de iterar sobre esse array, não comparar um único campo.
- **`Config/system.php` não existe** — o mecanismo real chama-se `Config/core_config.php`, um array indexado (não associativo) de secções/campos, carregado em cada `ServiceProvider::registerConfig()` via `$this->mergeConfigFrom(__DIR__.'/../Config/core_config.php', 'core_config')`. Como é merge por `array_merge` num array de lista, cada package só acrescenta os seus próprios itens — não há colisão de chaves a evitar, só a chave `key` de cada item tem de ser única (ex.: `'zadarma'`, `'zadarma.settings'`). Ver exemplo real em `packages/Webkul/Admin/src/Config/core_config.php`, secção `general.magic_ai.settings` — é o exemplo mais próximo do que precisamos (`api_key` com `type: password`, `enable` com `type: boolean`, `depends: 'enable:1'`). Os valores são lidos/guardados via o serviço `Webkul\Core\SystemConfig` (helper `core()->getConfigData('zadarma.settings.api_key')`), não diretamente por `config()`.
- **Menu e ACL**: confirmados os shapes reais em `packages/Webkul/Admin/src/Config/menu.php` (merge em `menu.admin`) e `packages/Webkul/Admin/src/Config/acl.php` (merge em `acl`) — ambos arrays de listas com `key`/`name`/`route`/`sort`, igual ao que a secção 5 já assumia, sem correções necessárias aqui.
- **Rotas do admin**: o grupo de middleware real é `['web', 'admin_locale', 'user']` com `prefix(config('app.admin_path'))` (ver `AdminServiceProvider::boot()`). O nosso `SettingsController`/`CallController` devem registar as suas rotas dentro deste mesmo grupo/prefixo para ficarem protegidas pela autenticação admin e pelo ACL. A rota pública do `WebhookController` (modo webhook) **não** deve entrar neste grupo.
- **Frontend não é uma "app Vue" separada** — o admin do Krayin é Blade + Vue 3 montado inline via `<script type="text/x-template" id="...">` e `app.component('v-xxx-component', {...})` dentro do próprio `.blade.php` (ver `packages/Webkul/Admin/src/Resources/views/leads/common/contact.blade.php` como exemplo real de um componente Vue de contacto). O botão de ligar e o histórico de chamadas (fases 5-6) devem seguir este padrão — não vamos construir uma SPA Vue à parte nem ficheiros `.vue` compilados isoladamente.
- **`Http/Controllers` e `Routes/` não existem dentro dos packages de domínio** (`Lead`, `Contact`, `Email` só têm `Models/Repositories/Database/Providers`) — toda a UI/controllers/rotas do admin vivem centralizados em `packages/Webkul/Admin`. Isto é uma escolha de arquitetura do Krayin, não uma regra obrigatória para packages de terceiros — o nosso package Zadarma pode (e deve, para ficar autocontido) manter os seus próprios `Http/Controllers` e `Routes/routes.php`, tal como planeado na secção 5, em vez de meter tudo dentro do `Admin`.
- **Ambiente de desenvolvimento montado**: `docker-compose.yml` + `docker/php/Dockerfile` (PHP 8.3-FPM com `pdo_mysql, mbstring, exif, pcntl, bcmath, gd, zip, intl, xml, calendar`) + `docker/nginx/default.conf`, na raiz deste repo (versionados no git). Portas: app web em `:8080` (evita conflito com o OpenCRM, que usa `:5432`/`:9000-9001`), MySQL em `:3306`.

### 8.2 Ambiente corre dentro do WSL2, não no disco Windows (2026-07-03)

**A instância Krayin de referência (`krayin-app/`) vive dentro da distro WSL2 "Ubuntu"** (`~/dev/krayin-zadarma/` no filesystem nativo do Linux), **não** em `C:\Coding\krayin-zadarma\krayin-app` — essa pasta foi removida. Motivo: bind-mount do Docker Desktop a partir do disco Windows (`C:\...`) para o container Linux tornava qualquer pedido HTTP ~15 segundos (bootstrap completo do Laravel + várias centenas de packages/views/traduções, cada leitura de ficheiro pagando a travessia Windows↔WSL2). Mover o código para dentro do próprio filesystem do WSL2 baixou o tempo de resposta para ~50-150ms (confirmado por medição direta, ver commits/notas de sessão).

**Para correr comandos do ambiente a partir do Windows**, prefixar sempre com `wsl -d Ubuntu -- bash -lc "cd ~/dev/krayin-zadarma && <comando>"`, por exemplo:
```
wsl -d Ubuntu -- bash -lc "cd ~/dev/krayin-zadarma && docker compose up -d"
wsl -d Ubuntu -- bash -lc "cd ~/dev/krayin-zadarma && docker compose exec app php artisan migrate"
```
Isto requer que a integração WSL do Docker Desktop esteja ativa para a distro "Ubuntu" (Docker Desktop → Settings → Resources → WSL Integration). O `docker-compose.yml` deste repo (versionado no git, em `C:\Coding\krayin-zadarma\docker-compose.yml`) é idêntico ao usado dentro do WSL2 — só a localização física de onde corre é que muda; ao editar `docker-compose.yml`/`docker/` aqui no Windows, copiar as alterações para `~/dev/krayin-zadarma/` antes de correr (`cp docker-compose.yml docker/... ~/dev/krayin-zadarma/...`).

**Gotcha de permissões**: comandos `artisan`/`composer` corridos via `docker compose exec app ...` criam ficheiros como `root` dentro do container, mas o `php-fpm` que serve os pedidos HTTP corre como `www-data` — sem ajustar permissões, `storage/` e `bootstrap/cache` ficam ilegíveis/inescrevíveis para `www-data` e a app devolve HTTP 500 sem log (o próprio `storage/logs` não é escrevível). Corrigir com `docker compose exec app chown -R www-data:www-data storage bootstrap/cache` sempre que uma reinstalação de raiz for feita.

**Gotcha do package `packages/Webkul/Zadarma` dentro do container**: o código-fonte do package fica em `C:\Coding\krayin-zadarma\packages\Webkul\Zadarma` (git-tracked, editado com as ferramentas normais), fora da pasta `krayin-app/` (que não é git-tracked). Para ficar visível dentro do container Krayin, **não usar um symlink direto** dentro de `krayin-app/packages/Webkul/Zadarma` a apontar para `/mnt/c/...` — esse caminho só existe no host WSL2, não dentro do container, e o container vê o symlink como quebrado (`class_exists` falha silenciosamente, sem erro claro). A solução usada: um bind mount dedicado no `docker-compose.yml` (`./packages/Webkul/Zadarma:/var/www/html/packages/Webkul/Zadarma`, nos serviços `app` e `node`) + um symlink de topo em `~/dev/krayin-zadarma/packages -> /mnt/c/Coding/krayin-zadarma/packages` (feito uma vez, ao nível do host WSL, só para o caminho relativo `./packages/Webkul/Zadarma` do `docker-compose.yml` resolver corretamente e o ficheiro continuar idêntico entre a cópia Windows e a cópia WSL). Depois de qualquer alteração ao `docker-compose.yml`, copiar para `~/dev/krayin-zadarma/docker-compose.yml` e correr `docker compose up -d` para recriar os containers. Depois de adicionar/mudar autoload (`composer.json` root, `Webkul\Zadarma\` psr-4), correr sempre `composer dump-autoload` dentro do container.

## 9. Notas de segurança

- Nunca devolver `api_key`/`api_secret` em bruto por nenhuma rota (mesmo padrão do OpenCRM: `select` explícito + flag `has_credentials`).
- Em modo `webhook`, tratar a rota pública como fronteira de confiança: validar, limitar taxa, e nunca escrever diretamente em `call_records` sem validar a origem do pedido.
- Nunca imprimir `api_key`/`api_secret` em logs, terminal ou commits.
