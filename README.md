# MMV — Sistema de Gestão de Pedidos (Módulo 1)

Sistema web para o fluxo **Cotação → Liberação (PI) → Controle de Demandas → Engenharia → Output PDF**.

## Stack

- **Laravel 13** (PHP 8.2+) — monolito
- **Blade + Alpine.js 3 + Tailwind CSS** via **CDN (sem build step / sem Node)**
- **MySQL 8** em produção · **SQLite** no dev (já configurado)
- **Laravel Reverb** (WebSockets) — atualização em tempo real, sem recarregar a página
- `barryvdh/laravel-dompdf` (PDF) · `owen-it/laravel-auditing` (auditoria) · Sanctum

## Arquitetura (motores desacoplados do layout)

- **Motores** = `app/Services/*` (regra de negócio, sem HTTP) + endpoints **JSON** nos controllers
  (`Http/Resources/*`). O layout (Blade + Alpine) **consome** esses endpoints via `fetch`.
- **Componentes reutilizáveis**: Blade em `resources/views/components/*` (`x-modal`, `x-card`,
  `x-input`, `x-select`, `x-button`, `x-toast`, `x-status-badge`, `x-file-upload`).
- **Fábricas Alpine** em `public/js/alpine/factories.js`:
  - `liveResource()` — estado reativo ligado a um endpoint JSON + canal Echo (reuso central do real-time).
  - `itemsRepeater()` — linhas dinâmicas de itens (Cotação/Liberação).
  - `dependentSelects()` — dropdowns encadeados componente→categoria→tipo→material.
  - `niLookup()` — histórico por NI.
- **Real-time**: eventos `App\Events\*` (`ShouldBroadcastNow`) → Reverb → `liveResource` na tela.
  Os disparos são resilientes: se o Reverb estiver fora, a operação de negócio não quebra.
- **Admin**: CRUD genérico dirigido por `app/Admin/ResourceRegistry.php` (um controller + 2 views
  atendem todos os cadastros de apoio).

## Rodando em desenvolvimento

```bash
cd sistema
composer install            # se necessário
php artisan migrate --seed  # SQLite já configurado em .env

# Terminal 1 — app
php artisan serve

# Terminal 2 — WebSockets (real-time)
php artisan reverb:start
```

Acesse http://127.0.0.1:8000 → redireciona para o login.

### Usuários de teste (senha: `password`)

| E-mail | Perfil | Acesso |
|--------|--------|--------|
| admin@mmv.com | Administrador | tudo |
| engenharia@mmv.com | Engenharia | edita Demandas/Engenharia |
| comercial@mmv.com | Comercial | edita Cotação/Liberação |
| consulta@mmv.com | Consulta | somente leitura |

### Testar o tempo real
Abra **/demandas** em dois navegadores logados. Crie/aloque/mude o status em um — o outro
atualiza sozinho, **sem recarregar** (requer `reverb:start` rodando).

## Produção (Cloudways)

1. `.env`: trocar `DB_CONNECTION=mysql` (+ credenciais), `APP_ENV=production`, `APP_DEBUG=false`.
2. Reverb: rodar `php artisan reverb:start` como processo **Supervisor**; proxy WebSocket no Nginx
   para a porta do Reverb; ajustar `REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` para o domínio público.
3. Deploy: `git pull` + `php artisan migrate --force` + `php artisan config:cache`.
4. Uploads: `upload_max_filesize=20M`, `post_max_size=25M` no php.ini.

> Nota: `cdn.tailwindcss.com` é "dev only". Em produção, considere servir um CSS Tailwind
> pré-compilado estático (sem Node no servidor) — não altera a arquitetura.
