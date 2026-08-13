<?php
/**
 * Diagnostico do Sistema MMV — SOMENTE LEITURA.
 *
 * Existe para hospedagem compartilhada sem linha de comando: quando a aplicacao
 * responde erro 500, esta pagina mostra o motivo em vez da tela generica.
 *
 * Nao altera nada: nao roda migration, nao apaga cache, nao escreve no banco.
 * Para CORRIGIR, use installer.php (que tem os passos de instalacao/migracao).
 *
 * Apague este arquivo quando o sistema estiver estavel.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('display_errors', '1');
@set_time_limit(60);

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Linha de resultado: [rotulo, valor, estado] onde estado e ok|aviso|erro|neutro. */
$linhas = [];
$secoes = [];

function secao(string $titulo, array $linhas): array
{
    return ['titulo' => $titulo, 'linhas' => $linhas];
}

/* ------------------------------------------------------------------ *
 * 1) Ambiente PHP — roda mesmo se o Laravel nao subir                 *
 * ------------------------------------------------------------------ */
$obrigatorias = ['openssl', 'pdo', 'mbstring', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo'];
$php = [
    ['Versao do PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'erro'],
    ['upload_max_filesize', ini_get('upload_max_filesize'), 'neutro'],
    ['post_max_size', ini_get('post_max_size'), 'neutro'],
    ['memory_limit', ini_get('memory_limit'), 'neutro'],
    ['max_execution_time', ini_get('max_execution_time'), 'neutro'],
];

foreach ($obrigatorias as $ext) {
    $php[] = ['Extensao '.$ext, extension_loaded($ext) ? 'presente' : 'AUSENTE', extension_loaded($ext) ? 'ok' : 'erro'];
}

$php[] = ['Extensao pdo_sqlite', extension_loaded('pdo_sqlite') ? 'presente' : 'ausente', 'neutro'];
$php[] = ['Extensao pdo_mysql', extension_loaded('pdo_mysql') ? 'presente' : 'ausente', 'neutro'];

$secoes[] = secao('Ambiente PHP', $php);

/* ------------------------------------------------------------------ *
 * 2) Arquivos e permissoes — antes de tentar bootar o Laravel         *
 * ------------------------------------------------------------------ */
$BASE = null;
foreach ([dirname(__DIR__), __DIR__] as $cand) {
    if (is_file($cand.'/bootstrap/app.php')) {
        $BASE = $cand;
        break;
    }
}

$arquivos = [];

if ($BASE === null) {
    $arquivos[] = ['Raiz do Laravel', 'NAO ENCONTRADA a partir de '.__DIR__, 'erro'];
} else {
    $arquivos[] = ['Raiz do Laravel', $BASE, 'ok'];

    $checar = [
        '.env' => 'arquivo',
        'vendor/autoload.php' => 'arquivo',
        'storage' => 'gravavel',
        'storage/logs' => 'gravavel',
        'storage/framework/views' => 'gravavel',
        'storage/framework/cache' => 'gravavel',
        'storage/framework/sessions' => 'gravavel',
        'bootstrap/cache' => 'gravavel',
    ];

    foreach ($checar as $rel => $tipo) {
        $caminho = $BASE.'/'.$rel;

        if ($tipo === 'arquivo') {
            $arquivos[] = [$rel, is_file($caminho) ? 'existe' : 'AUSENTE', is_file($caminho) ? 'ok' : 'erro'];

            continue;
        }

        $existe = is_dir($caminho);
        $gravavel = $existe && is_writable($caminho);
        $arquivos[] = [
            $rel,
            $existe ? ($gravavel ? 'gravavel' : 'SEM PERMISSAO DE ESCRITA') : 'AUSENTE',
            $gravavel ? 'ok' : 'erro',
        ];
    }

    // Cache de config/rotas obsoleto e a causa classica de "atualizei e nao mudou nada".
    foreach (['config.php', 'routes-v7.php', 'services.php', 'packages.php'] as $cache) {
        $caminho = $BASE.'/bootstrap/cache/'.$cache;
        if (is_file($caminho)) {
            $arquivos[] = [
                'bootstrap/cache/'.$cache,
                'presente, gerado em '.date('d/m/Y H:i', (int) filemtime($caminho)),
                in_array($cache, ['config.php', 'routes-v7.php'], true) ? 'aviso' : 'neutro',
            ];
        }
    }

    // Data do codigo: revela deploy que nao chegou.
    foreach (['app/Services/MigracaoAutomaticaService.php', 'config/mmv.php', 'routes/web.php'] as $rel) {
        $caminho = $BASE.'/'.$rel;
        $arquivos[] = [
            'Codigo: '.$rel,
            is_file($caminho) ? 'presente, alterado em '.date('d/m/Y H:i', (int) filemtime($caminho)) : 'AUSENTE',
            is_file($caminho) ? 'ok' : 'aviso',
        ];
    }
}

$secoes[] = secao('Arquivos e permissoes', $arquivos);

/* ------------------------------------------------------------------ *
 * 3) Configuracao lida direto do .env, sem depender do framework      *
 * ------------------------------------------------------------------ */
$env = [];
$valores = [];

if ($BASE !== null && is_file($BASE.'/.env')) {
    foreach (file($BASE.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || str_starts_with($linha, '#') || ! str_contains($linha, '=')) {
            continue;
        }
        [$chave, $valor] = explode('=', $linha, 2);
        $valores[trim($chave)] = trim($valor, " \t\"'");
    }

    // Nada de segredo aqui: senhas e chaves ficam de fora de proposito.
    foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'CACHE_STORE', 'SESSION_DRIVER', 'FILESYSTEM_DISK', 'MIGRACAO_AUTOMATICA_NO_LOGIN'] as $chave) {
        $env[] = [$chave, $valores[$chave] ?? '(nao definido)', isset($valores[$chave]) ? 'ok' : 'neutro'];
    }

    $env[] = ['APP_KEY', isset($valores['APP_KEY']) && $valores['APP_KEY'] !== '' ? 'definida' : 'AUSENTE', isset($valores['APP_KEY']) && $valores['APP_KEY'] !== '' ? 'ok' : 'erro'];
} else {
    $env[] = ['.env', 'nao encontrado — a aplicacao nao sobe sem ele', 'erro'];
}

$secoes[] = secao('Configuracao (.env)', $env);

/* ------------------------------------------------------------------ *
 * 4) Banco: conexao, tabelas esperadas e migrations pendentes         *
 * ------------------------------------------------------------------ */
$banco = [];
$pdo = null;

if ($valores !== []) {
    $driver = $valores['DB_CONNECTION'] ?? 'sqlite';

    try {
        if ($driver === 'sqlite') {
            $arquivo = $valores['DB_DATABASE'] ?? ($BASE.'/database/database.sqlite');

            // Caminho relativo no .env e resolvido a partir da raiz do projeto.
            if (! str_starts_with($arquivo, '/')) {
                $arquivo = $BASE.'/'.ltrim($arquivo, '/');
            }

            $banco[] = ['Driver', 'SQLite', 'ok'];
            $banco[] = ['Arquivo', $arquivo, is_file($arquivo) ? 'ok' : 'erro'];

            if (is_file($arquivo)) {
                $banco[] = ['Tamanho', number_format(filesize($arquivo) / 1024, 1, ',', '.').' KB', 'neutro'];
                $banco[] = ['Gravavel', is_writable($arquivo) ? 'sim' : 'NAO — migration vai falhar', is_writable($arquivo) ? 'ok' : 'erro'];
                $pdo = new PDO('sqlite:'.$arquivo);
            } else {
                $banco[] = ['Situacao', 'arquivo do banco nao existe — use installer.php', 'erro'];
            }
        } else {
            $host = $valores['DB_HOST'] ?? '127.0.0.1';
            $porta = $valores['DB_PORT'] ?? '3306';
            $nome = $valores['DB_DATABASE'] ?? '';
            $banco[] = ['Driver', strtoupper($driver), 'ok'];
            $banco[] = ['Host / Banco', $host.' / '.$nome, 'neutro'];
            $pdo = new PDO("{$driver}:host={$host};port={$porta};dbname={$nome}", $valores['DB_USERNAME'] ?? '', $valores['DB_PASSWORD'] ?? '');
        }

        if ($pdo !== null) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $banco[] = ['Conexao', 'estabelecida', 'ok'];
        }
    } catch (Throwable $e) {
        $banco[] = ['Conexao', 'FALHOU: '.$e->getMessage(), 'erro'];
        $pdo = null;
    }
}

$migracoes = [];

if ($pdo !== null) {
    try {
        $aplicadas = $pdo->query('select migration from migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $aplicadas = [];
        $migracoes[] = ['Tabela migrations', 'NAO EXISTE — banco nunca foi instalado', 'erro'];
    }

    $emDisco = [];
    foreach (glob($BASE.'/database/migrations/*_*.php') ?: [] as $caminho) {
        $emDisco[] = basename($caminho, '.php');
    }

    $pendentes = array_values(array_diff($emDisco, $aplicadas));

    $migracoes[] = ['Migrations no codigo', (string) count($emDisco), 'neutro'];
    $migracoes[] = ['Migrations aplicadas', (string) count($aplicadas), 'neutro'];
    $migracoes[] = [
        'PENDENTES',
        $pendentes === [] ? 'nenhuma — banco atualizado' : implode(', ', $pendentes),
        $pendentes === [] ? 'ok' : 'erro',
    ];

    // Tabelas e colunas que o codigo novo exige. Ausencia = causa direta do erro 500.
    $exigidas = [
        'cliente_unidades' => null,
        'liberacoes' => 'unidade_id',
        'cotacoes' => 'unidade_id',
        'engenharia_headers' => 'unidade_id',
        'liberacao_itens' => 'nf_cliente',
        'materiais' => 'dimensoes',
        'audits' => null,
    ];

    foreach ($exigidas as $tabela => $coluna) {
        try {
            $pdo->query("select * from {$tabela} limit 1");
        } catch (Throwable $e) {
            $migracoes[] = ['Tabela '.$tabela, 'AUSENTE', 'erro'];

            continue;
        }

        if ($coluna === null) {
            $migracoes[] = ['Tabela '.$tabela, 'existe', 'ok'];

            continue;
        }

        try {
            $pdo->query("select {$coluna} from {$tabela} limit 1");
            $migracoes[] = [$tabela.'.'.$coluna, 'existe', 'ok'];
        } catch (Throwable $e) {
            $migracoes[] = [$tabela.'.'.$coluna, 'COLUNA AUSENTE — causa provavel do erro 500', 'erro'];
        }
    }
}

$secoes[] = secao('Banco de dados', $banco);

if ($migracoes !== []) {
    $secoes[] = secao('Schema e migrations', $migracoes);
}

/* ------------------------------------------------------------------ *
 * 5) Ultimo erro registrado — o que realmente responde "por que 500"  *
 * ------------------------------------------------------------------ */
$logTexto = '';

if ($BASE !== null) {
    $arquivoLog = $BASE.'/storage/logs/laravel.log';

    if (! is_file($arquivoLog)) {
        $logTexto = 'Nenhum storage/logs/laravel.log encontrado. Se a aplicacao esta dando 500 e nao ha log, '
            ."o mais provavel e que a pasta storage nao tenha permissao de escrita (veja a secao de arquivos acima).\n";
    } else {
        $tamanho = filesize($arquivoLog);
        $trecho = 24000;
        $handle = fopen($arquivoLog, 'r');
        if ($handle) {
            fseek($handle, max(0, $tamanho - $trecho));
            $logTexto = fread($handle, $trecho) ?: '';
            fclose($handle);
        }
        $logTexto = "Arquivo: {$arquivoLog}\nTamanho: ".number_format($tamanho / 1024, 1, ',', '.')." KB\n"
            .'Ultima escrita: '.date('d/m/Y H:i:s', (int) filemtime($arquivoLog))."\n"
            ."------------------------------------------------------------\n".$logTexto;
    }
}

/* ------------------------------------------------------------------ *
 * 6) Bootar o Laravel de fato — captura a excecao real do 500         *
 * ------------------------------------------------------------------ */
$boot = [];

if ($BASE !== null && is_file($BASE.'/vendor/autoload.php')) {
    try {
        require_once $BASE.'/vendor/autoload.php';
        $app = require $BASE.'/bootstrap/app.php';
        $boot[] = ['Autoload + bootstrap/app.php', 'ok', 'ok'];

        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        $boot[] = ['Bootstrap do framework', 'ok', 'ok'];
        $boot[] = ['Laravel', app()->version(), 'ok'];
        $boot[] = ['Ambiente', app()->environment(), 'neutro'];
        $boot[] = ['Config mmv carregada', config('mmv.migracao_automatica_no_login') === null ? 'NAO (config cache antigo?)' : 'sim', config('mmv.migracao_automatica_no_login') === null ? 'aviso' : 'ok'];
    } catch (Throwable $e) {
        $boot[] = ['FALHA AO SUBIR O LARAVEL', get_class($e).': '.$e->getMessage(), 'erro'];
        $boot[] = ['Arquivo', $e->getFile().':'.$e->getLine(), 'erro'];
        $logTexto = $e->getTraceAsString()."\n\n".$logTexto;
    }
} else {
    $boot[] = ['vendor/autoload.php', 'AUSENTE — rode composer install ou suba a pasta vendor', 'erro'];
}

$secoes[] = secao('Boot do Laravel', $boot);

$temErro = false;
foreach ($secoes as $s) {
    foreach ($s['linhas'] as $l) {
        if (($l[2] ?? '') === 'erro') {
            $temErro = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Diagnostico — Sistema MMV</title>
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
           background: #f4f4f5; color: #1e1e1e; margin: 0; padding: 24px; line-height: 1.5; }
    .wrap { max-width: 960px; margin: 0 auto; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .sub { color: #666; font-size: 13px; margin-bottom: 20px; }
    .banner { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
    .banner.ok { background: #dcfce7; color: #14532d; }
    .banner.erro { background: #fee2e2; color: #7f1d1d; }
    .card { background: #fff; border: 1px solid #e4e4e7; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
    .card h2 { font-size: 14px; margin: 0; padding: 10px 16px; background: #fafafa;
               border-bottom: 1px solid #e4e4e7; text-transform: uppercase; letter-spacing: .04em; color: #52525b; }
    table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    td { padding: 7px 16px; border-bottom: 1px solid #f4f4f5; vertical-align: top; }
    tr:last-child td { border-bottom: none; }
    td.rotulo { color: #52525b; width: 38%; }
    td.valor { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-word; }
    .ok { color: #15803d; } .erro { color: #b91c1c; font-weight: 600; }
    .aviso { color: #b45309; } .neutro { color: #3f3f46; }
    pre { background: #18181b; color: #e4e4e7; padding: 16px; border-radius: 8px;
          overflow-x: auto; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
    .rodape { font-size: 13px; color: #666; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e4e4e7; }
    code { background: #f4f4f5; padding: 1px 5px; border-radius: 3px; font-size: 12.5px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Diagnostico — Sistema MMV</h1>
    <div class="sub">Somente leitura. Gerado em <?= date('d/m/Y H:i:s') ?>.</div>

    <div class="banner <?= $temErro ? 'erro' : 'ok' ?>">
        <?= $temErro
            ? 'Foram encontrados problemas. Veja em vermelho abaixo.'
            : 'Nenhum problema encontrado nas verificacoes.' ?>
    </div>

    <?php foreach ($secoes as $s): ?>
        <?php if ($s['linhas'] === []) {
            continue;
        } ?>
        <div class="card">
            <h2><?= h($s['titulo']) ?></h2>
            <table>
                <?php foreach ($s['linhas'] as $l): ?>
                    <tr>
                        <td class="rotulo"><?= h($l[0]) ?></td>
                        <td class="valor <?= h($l[2] ?? 'neutro') ?>"><?= h($l[1]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endforeach; ?>

    <div class="card">
        <h2>Ultimas linhas do log</h2>
        <div style="padding:16px"><pre><?= h($logTexto !== '' ? $logTexto : 'Sem conteudo.') ?></pre></div>
    </div>

    <div class="rodape">
        Para CORRIGIR: acesse <code>installer.php</code> (ou <code>installer.php?force=1</code> para reinstalar do zero,
        o que APAGA todos os dados). Esta pagina nao altera nada.<br>
        Apague <code>diagnostico.php</code> quando o sistema estiver estavel.
    </div>
</div>
</body>
</html>
