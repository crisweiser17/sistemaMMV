<?php
/**
 * Instalador web do Sistema MMV (Laravel 13).
 *
 * Use em hospedagem compartilhada onde nao ha acesso a linha de comando.
 * Faca upload de TODO o projeto (incluindo vendor/) e acesse este arquivo pelo navegador:
 *     https://seu-dominio/installer.php
 *
 * Ele faz: checagem de requisitos -> .env + APP_KEY -> banco (SQLite/MySQL)
 *          -> migrations + dados essenciais -> conta de administrador -> trava (install.lock).
 *
 * IMPORTANTE: apague este arquivo (installer.php) apos concluir a instalacao.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('display_errors', '1');
@set_time_limit(300);

/* ------------------------------------------------------------------ *
 * 0) Localiza a raiz do Laravel (este arquivo pode estar em public/   *
 *    ou na raiz do projeto).                                          *
 * ------------------------------------------------------------------ */
$BASE = null;
foreach ([dirname(__DIR__), __DIR__] as $cand) {
    if (is_file($cand . '/bootstrap/app.php')) { $BASE = $cand; break; }
}

$LOCK = $BASE ? $BASE . '/storage/installed.lock' : null;
$step = $_POST['step'] ?? $_GET['step'] ?? 'requirements';
$forced = isset($_GET['force']);

/* ------------------------------------------------------------------ *
 * Helpers                                                             *
 * ------------------------------------------------------------------ */
function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function detectBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Caminho ate o diretorio do installer (sem o nome do arquivo).
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return rtrim($scheme . '://' . $host . $dir, '/');
}

/** Requisitos de PHP/extensoes. Retorna [label, ok, obrigatorio]. */
function requirements(): array
{
    $ext = fn (string $e) => extension_loaded($e);
    return [
        ['PHP >= 8.2 (atual: ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.2.0', '>='), true],
        ['Extensao OpenSSL', $ext('openssl'), true],
        ['Extensao PDO', $ext('pdo'), true],
        ['Extensao Mbstring', $ext('mbstring'), true],
        ['Extensao Tokenizer', $ext('tokenizer'), true],
        ['Extensao XML', $ext('xml'), true],
        ['Extensao Ctype', $ext('ctype'), true],
        ['Extensao JSON', $ext('json'), true],
        ['Extensao Fileinfo', $ext('fileinfo'), true],
        ['Extensao cURL', $ext('curl'), true],
        ['Extensao DOM (PDF)', $ext('dom'), true],
        ['Driver SQLite (pdo_sqlite)', $ext('pdo_sqlite'), false],
        ['Driver MySQL (pdo_mysql)', $ext('pdo_mysql'), false],
        ['Extensao GD (imagens no PDF) — recomendada', $ext('gd'), false],
    ];
}

/** Diretorios que precisam ser graváveis. */
function writablePaths(string $base): array
{
    return [
        $base . '/storage',
        $base . '/storage/framework',
        $base . '/storage/framework/cache',
        $base . '/storage/framework/sessions',
        $base . '/storage/framework/views',
        $base . '/storage/logs',
        $base . '/bootstrap/cache',
        $base . '/database',
    ];
}

function ensureWritable(string $base): array
{
    $report = [];
    foreach (writablePaths($base) as $p) {
        if (!is_dir($p)) { @mkdir($p, 0775, true); }
        if (is_dir($p) && !is_writable($p)) { @chmod($p, 0775); }
        $report[] = [str_replace($base . '/', '', $p), is_dir($p) && is_writable($p)];
    }
    return $report;
}

function envEscape(string $v): string
{
    // Aspas duplas quando houver espacos/caracteres especiais.
    if ($v === '' || preg_match('/[\s#"\']/', $v)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
    return $v;
}

function buildEnv(array $c): string
{
    $key = 'base64:' . base64_encode(random_bytes(32));
    $lines = [
        'APP_NAME'              => $c['app_name'],
        'APP_ENV'              => 'production',
        'APP_KEY'              => $key,
        'APP_DEBUG'           => 'false',
        'APP_URL'             => $c['app_url'],
        '',
        'APP_LOCALE'           => 'pt_BR',
        'APP_FALLBACK_LOCALE'  => 'en',
        'APP_FAKER_LOCALE'     => 'pt_BR',
        '',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'BCRYPT_ROUNDS'        => '12',
        '',
        'LOG_CHANNEL'          => 'stack',
        'LOG_STACK'            => 'single',
        'LOG_LEVEL'            => 'error',
        '',
    ];

    if ($c['db_connection'] === 'mysql') {
        $lines += [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST'       => $c['db_host'],
            'DB_PORT'       => $c['db_port'],
            'DB_DATABASE'   => $c['db_database'],
            'DB_USERNAME'   => $c['db_username'],
            'DB_PASSWORD'   => $c['db_password'],
            '',
        ];
    } else {
        $lines += [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE'   => $c['db_sqlite_path'],
            '',
        ];
    }

    $lines += [
        'SESSION_DRIVER'   => 'database',
        'SESSION_LIFETIME' => '120',
        'SESSION_ENCRYPT'  => 'false',
        'SESSION_PATH'     => '/',
        'SESSION_DOMAIN'   => 'null',
        '',
        // Reverb (websocket) normalmente NAO roda em hospedagem compartilhada:
        // broadcast vai para log; o app funciona sem tempo real.
        'BROADCAST_CONNECTION' => 'log',
        'FILESYSTEM_DISK'      => 'local',
        'QUEUE_CONNECTION'     => 'database',
        'CACHE_STORE'          => 'database',
        '',
        'MAIL_MAILER'          => 'log',
        'MAIL_FROM_ADDRESS'    => $c['admin_email'],
        'MAIL_FROM_NAME'       => $c['app_name'],
    ];

    $out = '';
    foreach ($lines as $k => $v) {
        if ($k === '' || (is_int($k) && $v === '')) { $out .= "\n"; continue; }
        $out .= $k . '=' . envEscape((string) $v) . "\n";
    }
    return $out;
}

/**
 * Verifica se o vendor/ instalado corresponde ao projeto (Laravel 11+).
 * Retorna [ok, mensagem, versao].
 */
function installedLaravel(string $base): array
{
    $auto = $base . '/vendor/autoload.php';
    if (!is_file($auto)) {
        return [false, 'vendor/ ausente — rode "composer install" no servidor', null];
    }
    require_once $auto;
    if (!class_exists(\Illuminate\Foundation\Application::class)) {
        return [false, 'Laravel nao encontrado em vendor/ — rode "composer install"', null];
    }
    $ver = (string) \Illuminate\Foundation\Application::VERSION;
    $ok = method_exists(\Illuminate\Foundation\Application::class, 'configure')
        && version_compare($ver, '11.0', '>=');
    $msg = $ok
        ? 'Dependencias OK (Laravel ' . $ver . ')'
        : 'vendor/ incompativel (Laravel ' . $ver . ') — rode "composer install" para instalar a versao do projeto';
    return [$ok, $msg, $ver];
}

/** Inicializa o framework para rodar comandos Artisan dentro da requisicao. */
function bootLaravel(string $base)
{
    // Limpa caches que poderiam apontar para configuracoes antigas.
    foreach (['config.php', 'routes.php', 'events.php', 'services.php', 'packages.php'] as $f) {
        @unlink($base . '/bootstrap/cache/' . $f);
    }
    require $base . '/vendor/autoload.php';
    $app = require $base . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    return $app;
}

/* ================================================================== *
 * Pagina ja instalada?                                               *
 * ================================================================== */
$alreadyInstalled = $LOCK && is_file($LOCK) && !$forced;

/* ================================================================== *
 * PROCESSAMENTO DO PASSO "run" (executa a instalacao)                *
 * ================================================================== */
$result = null;
if ($step === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST' && $BASE && !$alreadyInstalled) {
    $log = [];
    $ok = true;
    try {
        $appName  = trim($_POST['app_name'] ?? 'MMV Equipamentos');
        $appUrl   = rtrim(trim($_POST['app_url'] ?? detectBaseUrl()), '/');
        // APP_URL deve apontar para a raiz publica (sem /installer.php).
        $appUrl   = preg_replace('#/installer\.php.*$#', '', $appUrl);

        $dbConn   = ($_POST['db_connection'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
        $sqlitePath = $BASE . '/database/database.sqlite';

        $adminName  = trim($_POST['admin_name'] ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass  = (string) ($_POST['admin_password'] ?? '');
        $withDemo   = !empty($_POST['with_demo']);
        $removeDemo = !empty($_POST['remove_demo']);

        // ---- Validacoes ----
        if ($adminName === '') { throw new RuntimeException('Informe o nome do administrador.'); }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('E-mail do administrador invalido.'); }
        if (strlen($adminPass) < 8) { throw new RuntimeException('A senha do administrador deve ter ao menos 8 caracteres.'); }

        $config = [
            'app_name'       => $appName,
            'app_url'        => $appUrl,
            'db_connection'  => $dbConn,
            'db_sqlite_path' => $sqlitePath,
            'admin_email'    => $adminEmail,
        ];

        if ($dbConn === 'mysql') {
            $config['db_host']     = trim($_POST['db_host'] ?? '127.0.0.1');
            $config['db_port']     = trim($_POST['db_port'] ?? '3306');
            $config['db_database'] = trim($_POST['db_database'] ?? '');
            $config['db_username'] = trim($_POST['db_username'] ?? '');
            $config['db_password'] = (string) ($_POST['db_password'] ?? '');

            // Testa a conexao MySQL antes de prosseguir.
            $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_database']}";
            try {
                new PDO($dsn, $config['db_username'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $log[] = ['Conexao MySQL OK', true];
            } catch (Throwable $e) {
                throw new RuntimeException('Falha ao conectar no MySQL: ' . $e->getMessage());
            }
        } else {
            // SQLite: recria o arquivo limpo para uma instalacao de producao.
            @unlink($sqlitePath);
            if (@touch($sqlitePath) === false) {
                throw new RuntimeException('Nao foi possivel criar o arquivo SQLite em database/. Verifique as permissoes.');
            }
            @chmod($sqlitePath, 0664);
            $log[] = ['Banco SQLite criado em database/database.sqlite', true];
        }

        // ---- Permissoes ----
        foreach (ensureWritable($BASE) as [$p, $w]) {
            $log[] = ['Gravavel: ' . $p, $w];
            if (!$w) { $ok = false; }
        }
        if (!$ok) { throw new RuntimeException('Existem diretorios sem permissao de escrita (veja acima). Ajuste para 775 e tente novamente.'); }

        // ---- Escreve .env ----
        $env = buildEnv($config);
        if (file_put_contents($BASE . '/.env', $env) === false) {
            throw new RuntimeException('Nao foi possivel escrever o arquivo .env na raiz do projeto.');
        }
        $log[] = ['.env gerado com APP_KEY', true];

        // ---- Verifica se vendor/ corresponde ao projeto (evita "Application::configure does not exist") ----
        [$lvOk, $lvMsg] = installedLaravel($BASE);
        $log[] = [$lvMsg, $lvOk];
        if (!$lvOk) {
            throw new RuntimeException(
                $lvMsg . '. Conecte por SSH na pasta da aplicacao e rode: composer install --no-dev --optimize-autoloader'
            );
        }

        // ---- Boot do Laravel + migrations + seed ----
        $app = bootLaravel($BASE);
        Illuminate\Support\Facades\Artisan::call('config:clear');

        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $log[] = ['Migrations executadas', true];

        Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
        $log[] = ['Dados essenciais (perfis, status, unidades...) inseridos', true];

        if ($withDemo) {
            Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DemoSeeder', '--force' => true]);
            $log[] = ['Dados de demonstracao inseridos', true];
        }

        // ---- Conta de administrador ----
        $perfilAdmin = App\Models\Perfil::where('nome', 'Administrador')->first();
        if (!$perfilAdmin) { throw new RuntimeException('Perfil "Administrador" nao encontrado apos o seed.'); }

        App\Models\User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name'      => $adminName,
                'password'  => Illuminate\Support\Facades\Hash::make($adminPass),
                'perfil_id' => $perfilAdmin->id,
                'ativo'     => true,
            ]
        );
        $log[] = ['Administrador criado: ' . $adminEmail, true];

        // ---- Remove contas de demonstracao com senha padrao ----
        if ($removeDemo) {
            $demoEmails = array_diff(
                ['admin@mmv.com', 'engenharia@mmv.com', 'comercial@mmv.com', 'consulta@mmv.com'],
                [$adminEmail]
            );
            $n = App\Models\User::whereIn('email', $demoEmails)->delete();
            $log[] = ['Contas de demonstracao removidas: ' . $n, true];
        }

        // ---- Otimizacao para producao (seguro: views) ----
        Illuminate\Support\Facades\Artisan::call('view:cache');
        Illuminate\Support\Facades\Artisan::call('config:cache');
        $log[] = ['Cache de config/views gerado (producao)', true];

        // ---- Trava ----
        @file_put_contents($LOCK, 'Instalado em ' . date('c'));
        $log[] = ['Instalacao concluida e travada (storage/installed.lock)', true];

        $result = ['ok' => true, 'log' => $log, 'app_url' => $appUrl];
    } catch (Throwable $e) {
        $log[] = ['ERRO: ' . $e->getMessage(), false];
        $result = ['ok' => false, 'log' => $log];
    }
    $step = 'done';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalador · Sistema MMV</title>
<style>
    :root { --ink:#1E1E1E; --accent:#EF8332; --accent-dark:#A8500F; --ok:#10b981; --err:#dc2626; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:#f4f4f5; color:#27272a; line-height:1.5; }
    .wrap { max-width: 760px; margin: 0 auto; padding: 32px 20px 80px; }
    .brand { display:flex; align-items:center; gap:12px; margin-bottom: 8px; }
    .brand .logo { background:var(--ink); color:#fff; font-weight:800; letter-spacing:.5px; padding:8px 12px; border-radius:8px; }
    .brand .logo b { color:var(--accent); }
    h1 { font-size: 1.35rem; margin: 18px 0 4px; color: var(--ink); }
    p.sub { color:#71717a; margin:0 0 20px; font-size:.95rem; }
    .card { background:#fff; border:1px solid #e4e4e7; border-radius:12px; padding:22px; margin-bottom:18px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .card h2 { font-size:1.05rem; margin:0 0 14px; color:var(--ink); }
    ul.checks { list-style:none; margin:0; padding:0; }
    ul.checks li { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px dashed #f0f0f0; font-size:.92rem; }
    ul.checks li:last-child { border-bottom:0; }
    .pill { font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:999px; white-space:nowrap; }
    .pill.ok { background:#dcfce7; color:#15803d; }
    .pill.err { background:#fee2e2; color:#b91c1c; }
    .pill.warn { background:#fef3c7; color:#b45309; }
    label { display:block; font-size:.85rem; font-weight:600; color:#3f3f46; margin:14px 0 5px; }
    input[type=text], input[type=email], input[type=password], input[type=number] {
        width:100%; padding:11px 12px; border:1px solid #d4d4d8; border-radius:8px; font-size:.95rem; background:#fafafa;
    }
    input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(239,131,50,.2); background:#fff; }
    .row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:560px){ .row { grid-template-columns:1fr; } }
    .hint { font-size:.8rem; color:#a1a1aa; margin-top:4px; }
    .check-inline { display:flex; align-items:flex-start; gap:10px; margin-top:16px; font-size:.9rem; }
    .check-inline input { margin-top:3px; }
    .btn { display:inline-flex; align-items:center; gap:8px; background:var(--accent); color:var(--ink); font-weight:700;
           border:0; padding:12px 20px; border-radius:8px; font-size:.95rem; cursor:pointer; text-decoration:none; transition:background .15s; }
    .btn:hover { background:#e06e18; }
    .btn.secondary { background:#fff; color:#3f3f46; border:1px solid #d4d4d8; font-weight:600; }
    .btn.secondary:hover { background:#f4f4f5; }
    .actions { display:flex; gap:10px; margin-top:22px; flex-wrap:wrap; }
    .seg { display:flex; gap:8px; margin-top:6px; }
    .seg label { display:flex; align-items:center; gap:8px; flex:1; margin:0; padding:12px; border:1px solid #d4d4d8; border-radius:8px; cursor:pointer; font-weight:600; }
    .seg input { width:auto; }
    .seg label.active { border-color:var(--accent); background:#fff7ef; }
    .alert { padding:12px 14px; border-radius:8px; font-size:.9rem; margin-bottom:16px; }
    .alert.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .alert.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    code { background:#f4f4f5; padding:1px 6px; border-radius:5px; font-size:.85em; }
    .mysql-fields { display:none; }
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <span class="logo"><b>&#10003;</b> MMV</span>
        <span style="color:#a1a1aa;font-size:.85rem">Instalador do sistema</span>
    </div>

<?php if (!$BASE): ?>
    <div class="card">
        <div class="alert err">
            Nao encontrei o projeto Laravel. Coloque <code>installer.php</code> dentro da pasta <code>public/</code>
            do projeto (ou na raiz, ao lado de <code>bootstrap/</code>) e tente novamente.
        </div>
    </div>

<?php elseif ($alreadyInstalled): ?>
    <h1>Sistema ja instalado</h1>
    <p class="sub">Encontrei <code>storage/installed.lock</code>.</p>
    <div class="card">
        <div class="alert ok">A instalacao ja foi concluida. Por seguranca, <b>apague o arquivo installer.php</b> do servidor.</div>
        <div class="actions">
            <a class="btn" href="<?= h(detectBaseUrl()) ?>/">Abrir o sistema</a>
            <a class="btn secondary" href="?force=1&step=requirements">Reinstalar mesmo assim</a>
        </div>
    </div>

<?php elseif ($step === 'done'): ?>
    <h1><?= $result['ok'] ? 'Instalacao concluida &#127881;' : 'Falha na instalacao' ?></h1>
    <p class="sub">Registro das etapas:</p>
    <div class="card">
        <ul class="checks">
            <?php foreach ($result['log'] as [$msg, $okItem]): ?>
                <li><span class="pill <?= $okItem ? 'ok' : 'err' ?>"><?= $okItem ? 'OK' : 'ERRO' ?></span> <?= h($msg) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($result['ok']): ?>
            <div class="alert ok" style="margin-top:18px">
                Tudo pronto! <b>Apague o arquivo <code>installer.php</code> agora</b> para proteger o sistema.
            </div>
            <div class="actions">
                <a class="btn" href="<?= h($result['app_url']) ?>/">Abrir o sistema</a>
            </div>
        <?php else: ?>
            <div class="alert err" style="margin-top:18px">Corrija o erro acima e tente novamente.</div>
            <div class="actions">
                <a class="btn secondary" href="?step=config">Voltar</a>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($step === 'config'): ?>
    <h1>Configuracao</h1>
    <p class="sub">Informe os dados do site, do banco e da conta de administrador.</p>
    <form method="post" action="?step=run">
        <input type="hidden" name="step" value="run">

        <div class="card">
            <h2>Aplicacao</h2>
            <label>Nome do sistema</label>
            <input type="text" name="app_name" value="MMV Equipamentos" required>
            <label>URL publica (APP_URL)</label>
            <input type="text" name="app_url" value="<?= h(preg_replace('#/installer\.php.*$#', '', detectBaseUrl())) ?>" required>
            <div class="hint">Endereco onde o sistema sera acessado (sem <code>/installer.php</code>).</div>
        </div>

        <div class="card">
            <h2>Banco de dados</h2>
            <div class="seg" id="dbseg">
                <label class="active"><input type="radio" name="db_connection" value="sqlite" checked> SQLite <span style="font-weight:400;color:#a1a1aa">(recomendado)</span></label>
                <label><input type="radio" name="db_connection" value="mysql"> MySQL</label>
            </div>
            <div class="hint" style="margin-top:8px">SQLite nao exige configuracao — cria um arquivo em <code>database/</code>. Use MySQL se preferir (ex.: cPanel).</div>

            <div class="mysql-fields" id="mysqlFields">
                <div class="row">
                    <div><label>Host</label><input type="text" name="db_host" value="127.0.0.1"></div>
                    <div><label>Porta</label><input type="number" name="db_port" value="3306"></div>
                </div>
                <label>Nome do banco</label>
                <input type="text" name="db_database" placeholder="ex.: usuario_mmv">
                <div class="row">
                    <div><label>Usuario</label><input type="text" name="db_username"></div>
                    <div><label>Senha</label><input type="password" name="db_password"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Administrador</h2>
            <label>Nome</label>
            <input type="text" name="admin_name" placeholder="Seu nome" required>
            <div class="row">
                <div><label>E-mail (login)</label><input type="email" name="admin_email" placeholder="voce@empresa.com" required></div>
                <div><label>Senha (min. 8)</label><input type="password" name="admin_password" minlength="8" required></div>
            </div>

            <label class="check-inline">
                <input type="checkbox" name="remove_demo" value="1" checked>
                <span>Remover contas de demonstracao (admin@mmv.com, engenharia@mmv.com, comercial@mmv.com, consulta@mmv.com — todas com senha <code>password</code>). <b>Recomendado.</b></span>
            </label>
            <label class="check-inline">
                <input type="checkbox" name="with_demo" value="1">
                <span>Inserir dados de demonstracao (cotacoes/PIs de exemplo). Deixe desmarcado para comecar vazio.</span>
            </label>
        </div>

        <div class="actions">
            <button class="btn" type="submit">Instalar agora</button>
            <a class="btn secondary" href="?step=requirements">Voltar</a>
        </div>
    </form>

    <script>
        const seg = document.getElementById('dbseg');
        const mysql = document.getElementById('mysqlFields');
        seg.addEventListener('change', () => {
            const v = seg.querySelector('input:checked').value;
            mysql.style.display = v === 'mysql' ? 'block' : 'none';
            seg.querySelectorAll('label').forEach(l => l.classList.toggle('active', l.querySelector('input').checked));
        });
    </script>

<?php else: /* requirements */ ?>
    <h1>Verificacao de requisitos</h1>
    <p class="sub">Confirme se o servidor atende aos requisitos antes de continuar.</p>

    <div class="card">
        <h2>PHP e extensoes</h2>
        <ul class="checks">
            <?php $blocker = false; foreach (requirements() as [$label, $okItem, $req]): ?>
                <li>
                    <?php if ($okItem): ?>
                        <span class="pill ok">OK</span>
                    <?php elseif ($req): $blocker = true; ?>
                        <span class="pill err">FALTA</span>
                    <?php else: ?>
                        <span class="pill warn">OPCIONAL</span>
                    <?php endif; ?>
                    <?= h($label) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card">
        <h2>Dependencias (vendor/)</h2>
        <?php [$lvOk, $lvMsg] = installedLaravel($BASE); if (!$lvOk) { $blocker = true; } ?>
        <ul class="checks">
            <li><span class="pill <?= $lvOk ? 'ok' : 'err' ?>"><?= $lvOk ? 'OK' : 'CORRIGIR' ?></span> <?= h($lvMsg) ?></li>
        </ul>
        <?php if (!$lvOk): ?>
            <div class="hint" style="margin-top:10px">
                Conecte por SSH na pasta da aplicacao e rode:
                <code>composer install --no-dev --optimize-autoloader</code>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Permissoes de escrita</h2>
        <ul class="checks">
            <?php foreach (ensureWritable($BASE) as [$p, $w]): if (!$w) { $blocker = true; } ?>
                <li><span class="pill <?= $w ? 'ok' : 'err' ?>"><?= $w ? 'OK' : 'SEM ESCRITA' ?></span> <code><?= h($p) ?></code></li>
            <?php endforeach; ?>
        </ul>
        <div class="hint" style="margin-top:10px">Se algum item estiver sem escrita, ajuste as permissoes para <code>775</code> (pastas) no gerenciador de arquivos / FTP.</div>
    </div>

    <?php if (!extension_loaded('pdo_sqlite') && !extension_loaded('pdo_mysql')): ?>
        <div class="alert err">Nenhum driver de banco disponivel (SQLite ou MySQL). Habilite ao menos um para continuar.</div>
    <?php endif; ?>

    <div class="actions">
        <?php if ($blocker): ?>
            <button class="btn" disabled style="opacity:.5;cursor:not-allowed">Corrija os itens em vermelho</button>
            <a class="btn secondary" href="?step=requirements">Verificar novamente</a>
        <?php else: ?>
            <a class="btn" href="?step=config">Continuar</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

    <p style="text-align:center;color:#a1a1aa;font-size:.78rem;margin-top:28px">
        Sistema MMV &middot; Apague <code>installer.php</code> apos a instalacao.
    </p>
</div>
</body>
</html>
