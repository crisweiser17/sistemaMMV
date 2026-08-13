<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Aplica migrations pendentes a partir da web.
 *
 * Existe por causa da hospedagem compartilhada, onde nao ha linha de comando:
 * um deploy sobe os arquivos novos, o banco fica para tras e a aplicacao passa
 * a dar 500 em toda tela que toca uma coluna que ainda nao existe.
 *
 * Roda apenas no login de um perfil autorizado. Nunca quebra o login: qualquer
 * falha e registrada e devolvida como mensagem, e a autenticacao segue.
 */
class MigracaoAutomaticaService
{
    /** Nome do arquivo de trava, para dois logins simultaneos nao migrarem juntos. */
    private const ARQUIVO_TRAVA = 'migracao-automatica.lock';

    /** Teto de execucao, para nao estourar o tempo de request da hospedagem. */
    private const SEGUNDOS_LIMITE = 120;

    /**
     * Mesmo padrao que o Migrator do Laravel usa para achar migrations. Se aqui
     * fosse `*.php`, qualquer sobra de deploy sem underscore (um leiame.php, por
     * exemplo) viraria uma pendencia que o `migrate` nunca resolve — e o login
     * do administrador chamaria o Artisan para sempre, sem nada mudar.
     */
    private const PADRAO_MIGRATION = 'migrations/*_*.php';

    /** A trava foi obtida: este request e o dono da migracao. */
    private const TRAVA_OBTIDA = 'obtida';

    /** Outro request esta migrando agora: este deve sair sem tentar. */
    private const TRAVA_OCUPADA = 'ocupada';

    /** Nao deu para criar/abrir o arquivo de trava: migra assim mesmo. */
    private const TRAVA_INDISPONIVEL = 'indisponivel';

    /** A copia de seguranca do banco foi gravada. */
    private const BACKUP_FEITO = 'feito';

    /** A conexao e SQLite mas nao deu para copiar o arquivo. */
    private const BACKUP_FALHOU = 'falhou';

    /** Nao ha copia barata para este banco (MySQL, Postgres, banco em memoria). */
    private const BACKUP_NAO_APLICAVEL = 'nao-aplicavel';

    /** Pasta dos backups, relativa a raiz do projeto — e assim que o operador a enxerga por FTP. */
    private const PASTA_BACKUPS = 'storage/app/backups';

    /** So migra quem tem o perfil autorizado, e so quando ha o que migrar. */
    public function deveExecutar(?User $usuario): bool
    {
        // Os defaults valem para o caso de um config cache antigo no servidor nao
        // conhecer a chave `mmv` — sem eles a funcao morreria calada justamente
        // no cenario que ela existe para resolver.
        if (! config('mmv.migracao_automatica_no_login', true)) {
            return false;
        }

        if (! $usuario?->temPerfil((string) config('mmv.perfil_migracao', 'Administrador'))) {
            return false;
        }

        return $this->pendentes() !== [];
    }

    /**
     * Migrations presentes em disco que ainda nao constam na tabela `migrations`.
     *
     * Comparado na mao, sem `migrate:status`, porque isto roda justamente quando
     * o schema esta incompleto — quanto menos a checagem depender do banco, melhor.
     *
     * @return list<string>
     */
    public function pendentes(): array
    {
        $arquivos = array_map(
            fn (string $caminho) => basename($caminho, '.php'),
            glob(database_path(self::PADRAO_MIGRATION)) ?: []
        );

        if ($arquivos === []) {
            return [];
        }

        try {
            // Sem a tabela de controle, nada foi aplicado ainda.
            if (! Schema::hasTable('migrations')) {
                return array_values($arquivos);
            }

            $aplicadas = DB::table('migrations')->pluck('migration')->all();
        } catch (Throwable $e) {
            Log::warning('Migracao automatica: nao foi possivel ler a tabela migrations: '.$e->getMessage());

            return [];
        }

        return array_values(array_diff($arquivos, $aplicadas));
    }

    /**
     * Aplica as migrations pendentes.
     *
     * `mensagem` e curta de proposito: ela vai para o toast, que tem largura de
     * uns 380px e some sozinho. O nome do banco e o caminho do backup vao em
     * `detalhe`, exibido como segunda linha — e informacao que o operador
     * precisa ANOTAR (ele nao tem o .env de producao em maos para saber se o
     * servidor roda SQLite ou MySQL), entao nao pode chegar truncada.
     *
     * @return array{ok: bool, mensagem: string, detalhe: string, aplicadas: list<string>}
     */
    public function executar(): array
    {
        $pendentes = $this->pendentes();

        if ($pendentes === []) {
            return [
                'ok' => true,
                'mensagem' => 'Banco ja estava atualizado.',
                'detalhe' => '',
                'aplicadas' => [],
            ];
        }

        $trava = $this->abrirTrava();

        // Outro login ja esta migrando: sai sem tentar de novo. Note que isto NAO
        // vale para o caso de nao ter conseguido abrir o arquivo de trava — ali a
        // migracao segue, senao um storage/app sem escrita deixaria o sistema em
        // erro 500 para sempre, sem ninguem entender por que nada acontece.
        if ($trava['estado'] === self::TRAVA_OCUPADA) {
            return [
                'ok' => true,
                'mensagem' => 'Atualizacao do banco ja em andamento em outra sessao. Aguarde e recarregue a pagina.',
                'detalhe' => '',
                'aplicadas' => [],
            ];
        }

        try {
            // Antes de qualquer trabalho pesado: a copia do banco tambem consome
            // tempo de request, e nao adianta esticar o limite depois de gastar.
            @set_time_limit(self::SEGUNDOS_LIMITE);

            // Se o operador fechar a aba no meio, o PHP nao pode abortar entre uma
            // migration e outra e deixar o schema pela metade.
            @ignore_user_abort(true);

            $backup = $this->protegerBancoSqlite();
            $detalhe = $this->descreverBanco($backup);

            $codigo = Artisan::call('migrate', ['--force' => true]);
            $saida = trim(Artisan::output());

            if ($codigo !== 0) {
                Log::error('Migracao automatica falhou', ['saida' => $saida]);

                return [
                    'ok' => false,
                    'mensagem' => 'Falha ao atualizar o banco. Verifique o log da aplicacao.',
                    'detalhe' => $detalhe,
                    'aplicadas' => [],
                ];
            }

            // O que de fato saiu da lista de pendentes. Contar $pendentes direto
            // faria a tela anunciar migrations que o Artisan nunca aplicou.
            $aplicadas = array_values(array_diff($pendentes, $this->pendentes()));

            if ($aplicadas === []) {
                Log::warning('Migracao automatica: o migrate rodou sem aplicar nada', [
                    'pendentes' => $pendentes,
                    'saida' => $saida,
                ]);

                return [
                    'ok' => true,
                    'mensagem' => 'Banco ja estava atualizado.',
                    'detalhe' => $detalhe,
                    'aplicadas' => [],
                ];
            }

            Log::info('Migracao automatica aplicada', [
                'migrations' => $aplicadas,
                'banco' => $detalhe,
                'saida' => $saida,
            ]);

            return [
                'ok' => true,
                'mensagem' => count($aplicadas) === 1
                    ? 'Banco atualizado: 1 migration aplicada.'
                    : 'Banco atualizado: '.count($aplicadas).' migrations aplicadas.',
                'detalhe' => $detalhe,
                'aplicadas' => $aplicadas,
            ];
        } catch (Throwable $e) {
            Log::error('Migracao automatica lancou excecao: '.$e->getMessage(), ['excecao' => $e]);

            return [
                'ok' => false,
                'mensagem' => 'Falha ao atualizar o banco: '.$e->getMessage(),
                'detalhe' => $detalhe ?? '',
                'aplicadas' => [],
            ];
        } finally {
            $this->fecharTrava($trava['handle']);
        }
    }

    /**
     * Trava exclusiva por arquivo. Preferida ao lock de cache porque o cache
     * deste projeto vive no banco — justamente o que pode estar incompleto aqui.
     *
     * Distingue os tres desfechos de proposito: "nao consegui travar" nao e a
     * mesma coisa que "outro processo esta migrando", e tratar os dois igual
     * transformaria uma pasta sem permissao em sistema fora do ar permanente.
     *
     * @return array{estado: string, handle: resource|null}
     */
    private function abrirTrava(): array
    {
        $pasta = storage_path('app');

        if (! is_dir($pasta)) {
            @mkdir($pasta, 0755, true);
        }

        $handle = @fopen($pasta.'/'.self::ARQUIVO_TRAVA, 'c');

        if ($handle === false) {
            Log::warning('Migracao automatica: nao foi possivel abrir o arquivo de trava; migrando sem exclusao mutua.');

            return ['estado' => self::TRAVA_INDISPONIVEL, 'handle' => null];
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return ['estado' => self::TRAVA_OCUPADA, 'handle' => null];
        }

        return ['estado' => self::TRAVA_OBTIDA, 'handle' => $handle];
    }

    /** @param resource|null $handle */
    private function fecharTrava($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Copia o arquivo do banco antes de migrar, quando a conexao e SQLite.
     * Em MySQL nao ha equivalente barato — o backup fica por conta do servidor.
     *
     * @return array{estado: string, caminho: string|null}
     */
    private function protegerBancoSqlite(): array
    {
        $semBackup = ['estado' => self::BACKUP_NAO_APLICAVEL, 'caminho' => null];

        try {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                return $semBackup;
            }

            $origem = DB::connection()->getDatabaseName();
        } catch (Throwable $e) {
            Log::warning('Migracao automatica: nao foi possivel identificar o banco: '.$e->getMessage());

            return $semBackup;
        }

        // Banco em memoria (suite de testes) nao tem arquivo para copiar.
        if (! is_string($origem) || ! is_file($origem)) {
            return $semBackup;
        }

        $destino = storage_path('app/backups');

        if (! is_dir($destino) && ! @mkdir($destino, 0755, true) && ! is_dir($destino)) {
            Log::warning('Migracao automatica: nao foi possivel criar a pasta de backup.');

            return ['estado' => self::BACKUP_FALHOU, 'caminho' => null];
        }

        // O PID entra no nome porque sem a trava (storage/app sem escrita) dois
        // requests no mesmo segundo sobrescreveriam um o backup do outro.
        $nome = 'banco-'.now()->format('Ymd_His').'-'.getmypid().'.sqlite';

        if (! @copy($origem, $destino.'/'.$nome)) {
            Log::warning('Migracao automatica: falha ao copiar o banco para backup.');

            return ['estado' => self::BACKUP_FALHOU, 'caminho' => null];
        }

        Log::info('Migracao automatica: backup do banco em '.$destino.'/'.$nome);

        return ['estado' => self::BACKUP_FEITO, 'caminho' => self::PASTA_BACKUPS.'/'.$nome];
    }

    /**
     * Nome legivel do banco em uso.
     *
     * O .env de producao nao sobe no deploy, entao o cliente nao tem como saber
     * daqui se o servidor roda SQLite ou MySQL — ele descobre por esta mensagem.
     */
    private function nomeDoDriver(): string
    {
        try {
            $driver = DB::connection()->getDriverName();
        } catch (Throwable $e) {
            return 'banco nao identificado';
        }

        return match ($driver) {
            'sqlite' => 'SQLite',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'pgsql' => 'PostgreSQL',
            'sqlsrv' => 'SQL Server',
            default => $driver,
        };
    }

    /**
     * Linha secundaria da mensagem: qual banco o servidor usa e o que houve com
     * o backup. Vai para a tela, nao so para o log, porque e justamente o que o
     * operador precisa saber e nao tem como descobrir sozinho.
     *
     * @param  array{estado: string, caminho: string|null}  $backup
     */
    private function descreverBanco(array $backup): string
    {
        $driver = $this->nomeDoDriver();

        return match ($backup['estado']) {
            self::BACKUP_FEITO => $driver.' — backup salvo em '.$backup['caminho'],
            self::BACKUP_FALHOU => $driver.' — ATENCAO: o backup automatico FALHOU; confira a permissao de escrita em '.self::PASTA_BACKUPS,
            default => $driver.' — sem backup automatico; use o painel da hospedagem.',
        };
    }
}
