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
     * @return array{ok: bool, mensagem: string, aplicadas: list<string>}
     */
    public function executar(): array
    {
        $pendentes = $this->pendentes();

        if ($pendentes === []) {
            return ['ok' => true, 'mensagem' => 'Banco ja estava atualizado.', 'aplicadas' => []];
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

            $this->protegerBancoSqlite();

            $codigo = Artisan::call('migrate', ['--force' => true]);
            $saida = trim(Artisan::output());

            if ($codigo !== 0) {
                Log::error('Migracao automatica falhou', ['saida' => $saida]);

                return [
                    'ok' => false,
                    'mensagem' => 'Falha ao atualizar o banco. Verifique o log da aplicacao.',
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

                return ['ok' => true, 'mensagem' => 'Banco ja estava atualizado.', 'aplicadas' => []];
            }

            Log::info('Migracao automatica aplicada', ['migrations' => $aplicadas, 'saida' => $saida]);

            return [
                'ok' => true,
                'mensagem' => count($aplicadas) === 1
                    ? 'Banco atualizado: 1 migration aplicada.'
                    : 'Banco atualizado: '.count($aplicadas).' migrations aplicadas.',
                'aplicadas' => $aplicadas,
            ];
        } catch (Throwable $e) {
            Log::error('Migracao automatica lancou excecao: '.$e->getMessage(), ['excecao' => $e]);

            return [
                'ok' => false,
                'mensagem' => 'Falha ao atualizar o banco: '.$e->getMessage(),
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
     */
    private function protegerBancoSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $origem = DB::connection()->getDatabaseName();

        if (! is_string($origem) || ! is_file($origem)) {
            return;
        }

        $destino = storage_path('app/backups');

        if (! is_dir($destino) && ! @mkdir($destino, 0755, true) && ! is_dir($destino)) {
            Log::warning('Migracao automatica: nao foi possivel criar a pasta de backup.');

            return;
        }

        // O PID entra no nome porque sem a trava (storage/app sem escrita) dois
        // requests no mesmo segundo sobrescreveriam um o backup do outro.
        $arquivo = $destino.'/banco-'.now()->format('Ymd_His').'-'.getmypid().'.sqlite';

        if (! @copy($origem, $arquivo)) {
            Log::warning('Migracao automatica: falha ao copiar o banco para backup.');

            return;
        }

        Log::info('Migracao automatica: backup do banco em '.$arquivo);
    }
}
