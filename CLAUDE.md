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

1. **Configurações** (Configuração > Zadarma, via `Config/core_config.php` — ver correção na secção 8.1): API key/secret (guardar em branco = manter atual), extensão/número do utilizador, modo de sincronização (informativo — o real vem do `.env`), ativar/desativar.
2. **Botão de ligar**: ícone junto aos campos de telefone nas fichas de Lead e de Person (Contacto) do Krayin — **não** existe campo de telefone em `Organization` nesta versão (ver secção 8.5), por isso não há botão aí — com confirmação antes de chamar `POST /v1/request/callback/` (mesma UX do OpenCRM — toca primeiro na extensão do utilizador, depois liga automaticamente ao número).
3. **Histórico de chamadas**: secção na ficha de Lead/Person listando `call_records` associados (correspondência por número de telefone, tolerante a formatação — reaproveitar a mesma lógica de "últimos 9 dígitos" já validada no OpenCRM).
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
| 5 | Botão de ligar (click-to-call) nas fichas de Lead e Person (não existe telefone em Organization, ver secção 8.5) |
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
- **`Config/system.php` não existe** — o mecanismo real chama-se `Config/core_config.php`, um array indexado (não associativo) de secções/campos, carregado em cada `ServiceProvider::registerConfig()` via `$this->mergeConfigFrom(__DIR__.'/../Config/core_config.php', 'core_config')`. Como é merge por `array_merge` num array de lista, cada package só acrescenta os seus próprios itens — não há colisão de chaves a evitar, só a chave `key` de cada item tem de ser única (ex.: `'zadarma'`, `'zadarma.settings'`). Ver exemplo real em `packages/Webkul/Admin/src/Config/core_config.php`, secção `general.magic_ai.settings` — é o exemplo mais próximo do que precisamos (`api_key` com `type: password`, `enable` com `type: boolean`, `depends: 'enable:1'`). Os valores são lidos/guardados via o serviço `Webkul\Core\SystemConfig` (helper **`system_config()->getConfigData('zadarma.settings.credentials.api_key')`** — não `core()`, que é um helper diferente, para `Webkul\Core\Core`/moeda/locale), não diretamente por `config()`. **Atenção à hierarquia**: uma entrada do tipo "página de configurações" (ex.: `zadarma.settings`) precisa de **um nível extra de filhos** com `fields` (ex.: `zadarma.settings.credentials`) — a página de edição do Krayin (`admin::configuration.edit`) só itera `getChildren()` do item ativo e renderiza os `fields` desses filhos; um item com `fields` diretos mas sem filhos próprios aparece com o formulário vazio, sem erro.
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

### 8.3 Confirmado/assumido na Fase 3 (2026-07-04): modo polling

- **Endpoint `/v1/statistics/` confirmado via documentação oficial** (fetch em 2026-07-04): parâmetros obrigatórios `start`/`end` (formato `YYYY-MM-DD HH:MM:SS`), opcionais `sip`/`cost_only`/`type`/`skip`/`limit`. Limites confirmados: máximo 30 dias por pedido, máximo 1000 linhas por página (paginar com `skip`). Campos de resposta confirmados por chamada: `id`, `sip`, `callstart`, `from`, `to`, `description`, `disposition`, `billseconds`, `cost`, `billcost`, `currency`.
- **Por confirmar com conta real (Fase 7) — não assumir como certo:**
  - **Direção da chamada** (`inbound`/`outbound`): a resposta documentada de `/v1/statistics/` **não** inclui um campo de direção explícito. `SyncCallsCommand::normalize()` tenta `call_type` ou `direction` defensivamente e cai para `'unknown'` se nenhum existir — a migração de `call_records.direction` tem `default('unknown')` por causa disto. Pode ser necessário usar `/v1/statistics/pbx/` (parâmetro `call_type` "in"/"out", ainda por confirmar se vem no corpo de cada registo ou é só filtro) ou inferir pela comparação entre `sip`/`caller_extension` configurado.
  - **URL de gravação**: não vem em `/v1/statistics/`. Existe um endpoint dedicado `GET /v1/pbx/record/request/` (parâmetros `call_id`/`pbx_call_id`, devolve `link`/`links`/`lifetime_till`) — ainda **não** foi ligado ao `SyncCallsCommand` (fica `recording_url: null` por agora). Ligar isto faz parte da Fase 6 ("aceder às gravações") ou pode ser adiantado na Fase 7 durante a validação com conta real, para não multiplicar chamadas à API por chamada sincronizada sem necessidade confirmada.
  - ~~**Chave do envelope da resposta**: assumi `$response['stats']`~~ **Confirmado na Fase 7 com conta real**: a chave é mesmo `stats`. Campos por chamada confirmados também: `id`, `sip`, `callstart`, `from`, `to`, `description`, `disposition`, `billseconds`, `cost`, `billcost`, `currency`, mais um extra não documentado antes — `hangupcause`. Continua **sem** nenhum campo de direção (`call_type`/`direction`) — a suposição de `'unknown'` mantém-se correta por agora.
- **`last_synced_at`** guardado como uma entrada `core_config` própria (`zadarma.settings.last_synced_at`), escrita diretamente via `Webkul\Core\Models\CoreConfig::updateOrCreate(...)` (não pelo fluxo genérico do formulário) — só avança depois de um ciclo de sincronização terminar sem exceção, para que uma falha a meio repita a mesma janela no próximo agendamento em vez de perder chamadas.
- **Lógica de correspondência por telefone partilhada**: criado `Webkul\Zadarma\Services\CallRecordSync` (normaliza, faz *match* por "últimos 9 dígitos" contra `persons.contact_numbers` — cada entrada é `{"value": ..., "label": "work"|"home"}`, confirmado lendo `phone.blade.php`/`v-inline-phone-edit` — e faz upsert por `external_id`). Tanto o `SyncCallsCommand` (Fase 3) como o futuro `WebhookController` (Fase 4) devem chamar este mesmo serviço, só a forma de obter os dados da chamada é que difere, exatamente como descrito na secção 3.

### 8.4 Confirmado/assumido na Fase 4 (2026-07-04): modo webhook

- **Nomes de eventos confirmados via documentação oficial** (3 fetches independentes, consistentes): `NOTIFY_START`, `NOTIFY_INTERNAL`, `NOTIFY_ANSWER`, `NOTIFY_END`, `NOTIFY_OUT_START`, `NOTIFY_OUT_END`, `NOTIFY_RECORD`, `NOTIFY_IVR`. Registo/ativação por tipo confirmado via `POST /v1/pbx/callinfo/notifications/`; URL do webhook em si regista-se via `POST /v1/pbx/callinfo/url/` (ambos aceitam parâmetro `url` — dá para automatizar no futuro, não feito ainda).
- **Por confirmar com conta real (Fase 7) — não assumir como certo:**
  - **Assinatura dos webhooks**: a documentação da Zadarma **não descreve** (ou o fetch não conseguiu aceder, a página é muito longa e trunca) um mecanismo de assinatura para notificações de webhook — só documenta o HMAC-SHA1 para os pedidos que NÓS fazemos à API deles. `WebhookSignatureVerifier` implementa um esquema best-effort (mesmo HMAC-SHA1+base64, sobre os parâmetros recebidos ordenados, à espera de um campo `signature`) que **não foi validado contra uma notificação real** — testar e ajustar na Fase 7 é essencial antes de expor isto a tráfego real.
  - **Nomes exatos dos parâmetros do payload** (`caller_id`, `called_did`, `duration`, `pbx_call_id`/`call_id`, `call_start`, `event`) em `WebhookController::handleCallEnd()` são também best-effort — o payload completo é sempre gravado em log (`Log::info('Zadarma webhook received.', ...)`) precisamente para permitir corrigir o mapeamento depois de ver uma notificação real, sem adivinhar às cegas.
  - **Allowlist de IPs**: confirmado por fetch que a Zadarma **não publica** ranges de IP para os servidores de webhook — não implementado, decisão consciente (não uma omissão).
- **Segurança confirmada e testada de ponta-a-ponta** (isto sim, com alta confiança, porque não depende de detalhes não documentados da Zadarma):
  - A rota pública (`POST /zadarma/webhook`) só é registada quando `config('zadarma.sync_mode') === 'webhook'` — testado: em modo `polling` a rota não existe (`route:list` não a lista); em modo `webhook`, existe.
  - Rate limiting via `RateLimiter::for('zadarma-webhook', ...)` (60/min), registado só junto com a rota.
  - Pedido sem `signature` → 403. Pedido com `signature` errada → 403. Pedido com assinatura correta (calculada com o `api_secret` guardado) → 200 e cria/atualiza o `call_records` correto — testado com um payload sintético (não uma notificação real da Zadarma).
  - Rota registada **sem** o grupo de middleware `web` (logo sem CSRF a interferir) — é um callback máquina-a-máquina, não um pedido de browser. Se algum dia precisar de estar dentro de `web` por outro motivo, usar `\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::except([...])` (método estático confirmado no Laravel 12 instalado) em vez de editar `krayin-app/bootstrap/app.php`.
- **Precedente real seguido**: o package `Webkul\WebForm` (formulários web públicos) já tem `Routes/routes.php` + `Http/Controllers` próprios carregados via `loadRoutesFrom()` no seu ServiceProvider — confirma que a estrutura planeada na secção 5 (rotas/controllers dentro do próprio package Zadarma, não centralizados no `Admin`) é um padrão real e não uma invenção nossa.

### 8.5 Confirmado na Fase 5 (2026-07-04): botão de ligar

- **Endpoint confirmado via documentação oficial**: `GET /v1/request/callback/`, parâmetros obrigatórios `from` (extensão/número que toca primeiro) e `to` (número a marcar), opcionais `sip`/`predicted`. Resposta `{"status":"success","from":...,"to":...,"time":...}` — corresponde exatamente à UX descrita na secção 6 ("toca primeiro na extensão, depois liga ao número").
- **Correção à secção 6**: `Organization` (`organizations` table) **não tem nenhum campo de telefone** — só `name` e `address` (JSON). O botão de ligar só faz sentido em `Lead` (via a `Person` associada) e na própria ficha de `Person` — não existe "telefone da organização" nesta versão do Krayin para ter um botão.
- **Mecanismo de extensão usado (sem editar ficheiros do Admin core)**: o helper `view_render_event($eventName, $params)` (em `Webkul\Core\Http\helpers.php`) dispara um evento Laravel (`Event::dispatch($eventName, $viewRenderEventManager)`) e qualquer package pode ouvir esse evento e chamar `$manager->addTemplate('namespace::view.path')` para injetar uma view num ponto específico de uma view core, sem a copiar/sobrepor. Usados os hooks reais `admin.leads.view.person.contact_numbers.after` (ficha de Lead) e `admin.contacts.persons.view.attributes.form_controls.attributes_view.after` (ficha de Person) — confirmados lendo `leads/view/person.blade.php` e `contacts/persons/view/attributes.blade.php`. Nenhum package do próprio Krayin usa `addTemplate` internamente (não há um exemplo real para copiar), mas o mecanismo em si está bem definido e documentado no código do `Core`.
- **Limitação do hook**: cada `view_render_event(...)` dispara **uma vez** no ponto onde está escrito na view, não uma vez por item de uma lista — por isso o nosso template injetado (`zadarma::components.call-button`) itera ele próprio `$person->contact_numbers` (recebendo `$lead`/`$person` dos parâmetros do evento original), em vez de assumir que seria chamado por número.
- **Ícone confirmado**: `icon-call` existe na fonte de ícones do Admin (`grep` confirmado em `assets/css`).
- Testado via `curl` autenticado (a extensão do Chrome estava com o mesmo problema de deteção "idle" das fases anteriores): o botão renderiza corretamente na ficha de Person com o número certo (`<v-zadarma-call-button number="+351 91 000 00 00">`); o pedido de chamada devolve 422 com mensagem clara quando a integração não está ativa, e 500 com mensagem clara (sem stack trace exposto) quando as credenciais são inválidas — não testado com uma chamada real (precisa de conta Zadarma real, Fase 7).

### 8.6 Confirmado na Fase 6 (2026-07-04): histórico de chamadas

- **Sem necessidade de repetir a correspondência por telefone na leitura**: como `CallRecordSync` (Fases 3-4) já grava `person_id` em `call_records` no momento da sincronização, a secção de histórico é uma simples consulta `where('person_id', ...)` — não precisa de refazer o "match últimos 9 dígitos" ao mostrar a ficha.
- Criado `Webkul\Zadarma\Repositories\CallRecordRepository` (extends `Webkul\Core\Eloquent\Repository`, mesmo padrão do `LeadRepository`/`CoreConfigRepository`) — confirmado que **não é preciso** um Contract/Concord (`ModuleServiceProvider`) para isto funcionar: `model()` pode devolver diretamente a classe concreta `CallRecord::class` (a classe base do Krayin só exige que o container consiga resolver o que `model()` devolve, não que seja uma interface). Mantivemos os modelos `CallRecord`/`ZadarmaAccount` sem Proxy desde a Fase 1 por não precisarmos de permitir que outro package os substitua — esta fase confirma que essa escolha não bloqueia nada.
- Reaproveitados os mesmos hooks `view_render_event` da Fase 5 (`admin.leads.view.person.contact_numbers.after` e `admin.contacts.persons.view.attributes.form_controls.attributes_view.after`) para injetar também `zadarma::components.call-history`, sem view/controller próprios.
- **Precedente confirmado para resolver um repositório diretamente numa Blade view** (não só em controllers): `leads/common/contact.blade.php` já faz `app('Webkul\Attribute\Repositories\AttributeRepository')->findOneWhere([...])` dentro de um `@php` block — o `call-history.blade.php` segue o mesmo padrão real do Krayin.
- Testado via `curl` autenticado na ficha de Person com 2 chamadas sintéticas (uma com gravação, outra sem, uma "answered" outra "no answer"): título "Call History" aparece, ambas as chamadas renderizam com direção/duração (`02:05`/`00:00`, formatação `gmdate('i:s', ...)`)/disposição corretas, e o link de download de gravação só aparece na chamada que tem `recording_url`. **Não testado na ficha de Lead diretamente** (criar um Lead válido exige várias FKs obrigatórias — pipeline/stage/source/type — fora do âmbito desta verificação); como usa exatamente o mesmo hook/template/repositório já validado na ficha de Person, o risco de divergência é baixo, mas fica por confirmar visualmente numa sessão futura ou na Fase 7.

### 8.7 Fase 7 (2026-07-06): validação com conta Zadarma real — **bug crítico de assinatura HMAC encontrado e corrigido**

- **Bug crítico em `ZadarmaClient::sign()` e `WebhookSignatureVerifier::verify()`**: ambos chamavam `hash_hmac('sha1', ..., $secret, true)` — o 4º parâmetro `true` força saída binária *raw*. O exemplo oficial da Zadarma (código PHP verbatim obtido da documentação) chama `hash_hmac('sha1', ..., $secret)` **sem** esse parâmetro, que por omissão é `false` e devolve a *string hexadecimal* — é essa string hex que deve ser passada a `base64_encode()`, não os bytes binários. Isto significa que **toda a assinatura estava errada desde a Fase 2** e nunca teria funcionado contra a API real (só passava nos testes porque esses testes comparavam a nossa implementação contra ela própria, calculada da mesma forma errada). Corrigido em ambas as classes — removido o `true`.
- **Segundo bug encontrado e corrigido no mesmo teste**: `ZadarmaClient::request()` passava o array `$params` diretamente à opção `'query'` do Guzzle, que o Guzzle serializa com a sua própria codificação (RFC3986, `%20` para espaços) — diferente da string usada para calcular a assinatura (`http_build_query()`, RFC1738, `+` para espaços). Como o exemplo oficial da Zadarma usa explicitamente `http_build_query($params, null, '&', PHP_QUERY_RFC1738)`, a correção foi construir a query string uma única vez e passá-la já pronta ao Guzzle (`'query' => $paramsString`), garantindo que a string assinada é byte-a-byte igual à realmente enviada.
- **Validado com sucesso contra a API real da Zadarma** (`GET /v1/info/balance/` sem parâmetros, e `GET /v1/statistics/` com parâmetros de data): ambos devolveram `status: success` depois da correção. Antes da correção, ambos devolviam 401 "Not authorized" mesmo com credenciais corretas — o que por si só já era um sinal de que o problema não era as credenciais, mas a assinatura.
- **Corrido `zadarma:sync-calls` a sério** contra a conta real: sincronizou chamadas com sucesso, `last_synced_at` avançou. Por precaução com dados pessoais de clientes reais, os valores devolvidos pela API (números, descrições) nunca foram impressos nesta conversa — só a estrutura (nomes de campos) e contagens agregadas.
- **`WebhookSignatureVerifier` corrigido pelo mesmo motivo mas continua sem validação contra uma notificação real** (precisa de um túnel público tipo `ngrok` para receber uma notificação real da Zadarma — ainda não configurado).
- **Click-to-call validado com uma chamada real completa** (autorização explícita do utilizador, 2026-07-06): primeiro testado diretamente via `ZadarmaClient` (isolando a assinatura), depois via o endpoint real `POST /admin/zadarma/call` (fluxo completo do botão) — ambos com sucesso (`status: success` / HTTP 200 `"Call requested — your extension will ring first."`).
  - **Descoberta importante durante este teste**: o valor de `caller_extension` guardado inicialmente pelo utilizador (`001`) estava **errado** — todos os pedidos falhavam com `400 "Check your phone number"` independentemente do formato do número `to` testado (nacional, com indicativo, com `+`). Só ao trocar o `from` para a extensão real (`110`) é que funcionou. Isto foi corrigido diretamente no `core_config` (`zadarma.settings.credentials.caller_extension`). **Lição:** esta mensagem de erro da Zadarma é enganadora — parece referir-se ao número de destino (`to`) mas pode ser causada pelo `from` (extensão de origem) estar errada. Se isto voltar a acontecer, verificar `from` antes de assumir que é o número marcado.
  - O número `to` que funcionou estava em formato E.164 completo (`+351XXXXXXXXX`, com `+`) — não foi testado se os outros formatos (nacional `XXXXXXXXX`, ou sem `+`) também funcionariam agora com o `from` corrigido, já que só se mudou uma variável de cada vez. Se o botão de ligar (que envia o número tal como está gravado em `contact_numbers`) passar a falhar por formato, confirmar isto.

## 9. Notas de segurança

- Nunca devolver `api_key`/`api_secret` em bruto por nenhuma rota (mesmo padrão do OpenCRM: `select` explícito + flag `has_credentials`).
- Em modo `webhook`, tratar a rota pública como fronteira de confiança: validar, limitar taxa, e nunca escrever diretamente em `call_records` sem validar a origem do pedido.
- Nunca imprimir `api_key`/`api_secret` em logs, terminal ou commits.
- **Limitação conhecida, herdada do Krayin (não introduzida por nós, Fase 2):** o mecanismo genérico `admin::configuration.field-type` injeta o valor atual de qualquer campo (incluindo `type: password`) diretamente no HTML da página de edição (`value="{{ $value }}"` passado ao componente Vue) — o mesmo acontece com o campo de password do IMAP nativo do Krayin. Ou seja, um admin autenticado com acesso à página de Configuração consegue ver o `api_key`/`api_secret` em claro via "Ver código-fonte"/DevTools, mesmo que o input apareça mascarado visualmente. Corrigir isto exigiria um `type: 'blade'` custom (mecanismo que existe mas não tem nenhum uso real nos packages do Krayin para copiar) — decidido não fazer isso na Fase 2 por ser fora do âmbito de um package de terceiros (seria preciso substituir comportamento do `Admin` core) e por ser uma exposição limitada a admins já autenticados, não pública. Revisitar se o utilizador pedir mais robustez aqui.

---

## 10. Extensão pós-roadmap (2026-07-06): extensão por utilizador + relatórios

Pedido do utilizador, fora do âmbito das 7 fases originais: cada utilizador Krayin poder configurar a sua própria extensão Zadarma (em vez de uma única partilhada), e uma página de estatísticas com gráficos diários por utilizador.

### Decisões de arquitetura

- **Extensão por utilizador é aditiva, não substitui a global**: cada utilizador pode configurar a sua própria extensão; quem não configurar continua a usar a extensão partilhada de `Configuration > Zadarma` como reserva. Ordem de resolução em `CallController::store()`: `UserExtension` do utilizador autenticado → `zadarma.settings.credentials.caller_extension` (global).
- **Tabela própria `zadarma_user_extensions`** (`user_id` único + `extension`), em vez de adicionar uma coluna à tabela `users` do Krayin (evita tocar em tabelas core, consistente com a prática de todo o resto do package).
- **Atribuição de chamadas a utilizadores via `sip`**: o campo `sip` que já vem em `/v1/statistics/` (confirmado real na Fase 7) é comparado contra `zadarma_user_extensions.extension` em `CallRecordSync::matchUser()`, preenchendo `call_records.user_id`. Para o modo webhook, tentamos os campos `internal`/`sip` do payload da notificação (best-effort, tal como o resto do mapeamento de webhook — ver secção 8.4).
- **Chamadas antigas (sincronizadas antes desta funcionalidade existir) ficam com `user_id = null`** — não há reprocessamento retroativo. Só chamadas sincronizadas depois desta alteração são atribuídas a um utilizador.

### UI

- **Extensão pessoal**: injetada na página "My Account" (`user/account/edit.blade.php`) via hook `admin.user.account.right.after`. **Não é um campo dentro do formulário principal da conta** — esse formulário exige sempre `current_password` para gravar (confirmado lendo `AccountController::update()`), o que seria mau UX só para guardar uma extensão. É um mini-componente Vue autónomo (`v-zadarma-my-extension`) com o seu próprio endpoint (`PUT admin/zadarma/my-extension`), tal como o botão de ligar.
- **Página de relatórios** (`GET admin/zadarma/reports`, novo item de menu "Zadarma Reports", `Config/menu.php`+`Config/acl.php` próprios — primeira vez que o package precisou de menu/ACL, todas as fases anteriores só estenderam páginas existentes via hooks): dois gráficos **Chart.js** (confirmado ser a biblioteca usada pelo dashboard nativo do Krayin, lendo `dashboard/index/revenue.blade.php` — mesmo padrão replicado: `new Chart(document.getElementById(id), {type: 'bar', data: {labels, datasets}, ...})`), "Calls per day" e "Call duration per day", cada um com 3 séries empilhadas (inbound/outbound/**unknown**). A série "unknown" foi incluída deliberadamente: como `/v1/statistics/` não devolve direção (limitação conhecida da Fase 3), qualquer chamada sincronizada por polling sem direção resolvida ficaria invisível nos gráficos se só houvesse inbound/outbound — a chamada real sincronizada na Fase 7 é precisamente um exemplo disto.
- Filtro por utilizador (dropdown "All users" + lista de `Webkul\User\Models\User`), endpoint de dados `GET admin/zadarma/reports/data` devolve JSON com 30 dias completos (dias sem chamadas aparecem como zero, não ficam em falta).

### Testado

- Prioridade extensão pessoal > global confirmada via tinker (trocando o valor pessoal e confirmando que prevalece).
- Atribuição por `sip` confirmada via `CallRecordSync::upsert()` com payloads sintéticos (`sip` correspondente → `user_id` certo; `sip` desconhecido → `user_id` null).
- Extensão pessoal gravada e lida com sucesso via `curl` autenticado (`PUT admin/zadarma/my-extension`), visível corretamente na página de perfil.
- Página e endpoint de relatórios testados via `curl` autenticado: JSON com 30 dias, zero-preenchido, mostra corretamente a chamada real da Fase 7 (com direção "unknown", exatamente como esperado) e responde corretamente ao filtro por utilizador.
- **Não testado**: atribuição de utilizador com uma sincronização real nova (a única chamada real existente na base de dados foi sincronizada antes desta funcionalidade existir, por isso tem `user_id = null` — comportamento correto, não um bug).

### 10.1 Correção (2026-07-06): Chart.js não carregava na página de relatórios

- **Bug encontrado pelo utilizador**: os gráficos apareciam vazios/não funcionavam na página `admin/zadarma/reports`.
- **Causa**: o Chart.js **não é** carregado globalmente no `app.js` do admin — só a própria view `dashboard/index.blade.php` inclui `<script type="module" src="{{ vite()->asset('js/chart.js') }}">` (confirmado lendo o ficheiro: é literalmente o bundle UMD do Chart.js v4.4.0, servido através do pipeline Vite do Admin, não um wrapper próprio). Como a nossa página de relatórios não incluía este script, `window.Chart` estava `undefined` e `new Chart(...)` falhava silenciosamente (sem erro visível na página, só na consola).
- **Correção**: adicionada a mesma tag `<script type="module" src="{{ vite()->asset('js/chart.js') }}"></script>` a `reports/index.blade.php`, antes do componente Vue. `vite()` é um helper global (`Webkul\Core\Http\helpers.php`) que resolve o manifesto do Admin, por isso funciona a partir do nosso próprio package sem precisarmos de build Vite próprio.
- Testado no browser: `typeof window.Chart === 'function'` e `Chart.getChart(canvas)` devolve uma instância real para os dois canvas (`zadarma_calls_chart`/`zadarma_duration_chart`) depois da correção.
- **Lição para páginas de admin futuras**: nem todo o JS "global" do admin Krayin está realmente no bundle principal — bibliotecas maiores como o Chart.js são carregadas por página, não centralizadas. Confirmar sempre onde uma biblioteca é carregada antes de assumir que está disponível globalmente.

### 10.2 Correção de dados (2026-07-06): extensão real é 102, não 110

O valor de extensão usado nos testes da Fase 7 (`110`) e depois configurado (global + pessoal do utilizador) estava desatualizado — o utilizador confirmou que a extensão real a usar é `102`. Atualizado em `core_config` (`zadarma.settings.credentials.caller_extension`) e em `zadarma_user_extensions` (utilizador atual).

### 10.3 Prefixo de encaminhamento de saída (2026-07-06)

Pedido do utilizador: replicar no click-to-call o mesmo hábito que já têm ao marcar manualmente no telemóvel — antepor `0001` ao número do cliente antes de pedir a chamada, para a central mostrar o número principal da empresa como identificador de chamada (CallerID) em vez do número direto.

- Novo valor de configuração `zadarma.outbound_prefix` (`Config/zadarma.php`, env `ZADARMA_OUTBOUND_PREFIX`, default `'0001'`) — configurável sem alterar código, já que é uma regra específica da central telefónica desta conta, não um comportamento genérico da Zadarma.
- `CallController::store()` antepõe este prefixo ao número (`to`) antes de pedir `/v1/request/callback/`. Aplicado sempre, sem opção de desativar por chamada — decisão explícita do utilizador ("aplica sempre automaticamente").
- **Não confirmado ainda com uma chamada real** que o CallerID mostrado ao cliente fica de facto correto (o utilizador optou por aplicar diretamente em vez de pedir um teste real primeiro) — se o CallerID aparecer errado num uso real, verificar aqui primeiro.
