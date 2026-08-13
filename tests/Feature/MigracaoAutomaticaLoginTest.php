<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use App\Services\MigracaoAutomaticaService;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * A hospedagem do cliente nao tem linha de comando: o deploy sobe arquivos por
 * FTP e as migrations novas nunca rodam, derrubando o sistema com erro 500.
 *
 * Aqui se garante que o login de um administrador aplica o que falta, que ele
 * NUNCA quebra por causa disso, e que ninguem mais consegue disparar migration.
 */
class MigracaoAutomaticaLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Arquivos criados pelo teste, removidos no tearDown. */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $caminho) {
            if (is_file($caminho)) {
                @unlink($caminho);
            }

            if (is_dir($caminho)) {
                @rmdir($caminho);
            }
        }

        $this->temporarios = [];

        parent::tearDown();
    }

    // ---- Apoio -------------------------------------------------------------

    private function usuario(string $perfil, string $email): User
    {
        $registro = Perfil::create(['nome' => $perfil, 'permissoes' => ['demanda' => 'ver'], 'ativo' => true]);

        return User::create([
            'name' => $perfil,
            'email' => $email,
            'password' => 'segredo123',
            'perfil_id' => $registro->id,
            'ativo' => true,
        ]);
    }

    private function administrador(): User
    {
        return $this->usuario('Administrador', 'admin@mmv.test');
    }

    /**
     * Escreve uma migration de verdade em database/migrations, para exercitar o
     * caminho completo (glob, tabela migrations, Artisan) em vez de mockar.
     */
    private function migrationTemporaria(string $sufixo, string $corpoUp): string
    {
        $caminho = database_path('migrations/9999_12_31_000001_'.$sufixo.'.php');

        $conteudo = <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
        {$corpoUp}
            }

            public function down(): void
            {
                Schema::dropIfExists('teste_migracao_automatica');
            }
        };
        PHP;

        file_put_contents($caminho, $conteudo);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    private function migrationQueCria(): string
    {
        return $this->migrationTemporaria('cria_tabela_de_teste', <<<'PHP'
                Schema::create('teste_migracao_automatica', function (Blueprint $table) {
                    $table->id();
                });
        PHP);
    }

    private function migrationQueExplode(): string
    {
        return $this->migrationTemporaria('explode', <<<'PHP'
                throw new RuntimeException('migration de teste falhou de proposito');
        PHP);
    }

    // ---- Caminho feliz -----------------------------------------------------

    public function test_administrador_com_migration_pendente_aplica_no_login(): void
    {
        $this->administrador();
        $this->migrationQueCria();

        $this->assertFalse(Schema::hasTable('teste_migracao_automatica'));

        $resposta = $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'segredo123']);

        $resposta->assertRedirect(route('demandas.index'));
        $resposta->assertSessionHas('success');
        $this->assertAuthenticated();
        $this->assertTrue(Schema::hasTable('teste_migracao_automatica'));
    }

    public function test_mensagem_de_sucesso_sobrevive_ao_redirect_intended(): void
    {
        $this->administrador();
        $this->migrationQueCria();

        $resposta = $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'segredo123']);

        $resposta->assertSessionHas('success', fn (string $mensagem) => str_contains($mensagem, 'Banco atualizado'));
    }

    // ---- Quem nao pode -----------------------------------------------------

    public function test_usuario_sem_perfil_administrador_loga_mas_nao_migra_nada(): void
    {
        $this->usuario('Comercial', 'comercial@mmv.test');
        $this->migrationQueCria();

        Artisan::spy();

        $resposta = $this->post('/login', ['email' => 'comercial@mmv.test', 'password' => 'segredo123']);

        $resposta->assertRedirect(route('demandas.index'));
        $resposta->assertSessionMissing('success');
        $this->assertAuthenticated();
        $this->assertFalse(Schema::hasTable('teste_migracao_automatica'));
        Artisan::shouldNotHaveReceived('call');
    }

    public function test_config_desligada_nao_migra_nem_para_administrador(): void
    {
        config(['mmv.migracao_automatica_no_login' => false]);

        $this->administrador();
        $this->migrationQueCria();

        Artisan::spy();

        $resposta = $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'segredo123']);

        $resposta->assertRedirect(route('demandas.index'));
        $resposta->assertSessionMissing('success');
        $this->assertAuthenticated();
        $this->assertFalse(Schema::hasTable('teste_migracao_automatica'));
        Artisan::shouldNotHaveReceived('call');
    }

    // ---- Custo quando nao ha nada a fazer ----------------------------------

    public function test_sem_pendencias_o_login_nao_chama_o_artisan(): void
    {
        $this->administrador();

        Artisan::spy();

        $resposta = $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'segredo123']);

        $resposta->assertRedirect(route('demandas.index'));
        $resposta->assertSessionMissing('success');
        $resposta->assertSessionMissing('error');
        $this->assertAuthenticated();
        Artisan::shouldNotHaveReceived('call');
    }

    public function test_arquivo_php_que_nao_e_migration_nao_conta_como_pendencia(): void
    {
        // Laravel so considera migration o arquivo no padrao `*_*.php`. Um README
        // ou sobra de deploy sem underscore nao pode fazer o servico chamar o
        // Artisan em todo login achando que ha pendencia eterna.
        $caminho = database_path('migrations/leiame.php');
        file_put_contents($caminho, "<?php\n\n// sobra de deploy\n");
        $this->temporarios[] = $caminho;

        $this->assertSame([], app(MigracaoAutomaticaService::class)->pendentes());
    }

    // ---- Falha ao migrar NAO pode derrubar o login -------------------------

    public function test_migration_que_explode_nao_impede_o_login(): void
    {
        $usuario = $this->administrador();
        $this->migrationQueExplode();

        $resposta = $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'segredo123']);

        $resposta->assertRedirect(route('demandas.index'));
        $resposta->assertSessionHas('error');
        $resposta->assertSessionMissing('success');
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_login_funciona_mesmo_com_a_tabela_de_log_quebrada(): void
    {
        // Cenario sem saida: um deploy futuro mexe em login_logs e o registro do
        // login passa a falhar ANTES da migracao rodar. O usuario precisa entrar
        // e a migracao precisa acontecer mesmo assim.
        $usuario = $this->administrador();
        $this->migrationQueCria();

        Schema::drop('login_logs');

        $resposta = $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'segredo123']);

        $resposta->assertRedirect(route('demandas.index'));
        $this->assertAuthenticatedAs($usuario);
        $this->assertTrue(Schema::hasTable('teste_migracao_automatica'));
    }

    // ---- Login normal segue intacto ----------------------------------------

    public function test_credenciais_invalidas_continuam_sendo_rejeitadas(): void
    {
        $this->administrador();
        $this->migrationQueCria();

        Artisan::spy();

        $resposta = $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'errada']);

        $resposta->assertRedirect('/');
        $resposta->assertSessionHas('error', 'Credenciais invalidas ou usuario inativo.');
        $this->assertGuest();
        $this->assertFalse(Schema::hasTable('teste_migracao_automatica'));
        Artisan::shouldNotHaveReceived('call');
    }

    public function test_usuario_inativo_nao_dispara_migracao(): void
    {
        $usuario = $this->administrador();
        $usuario->update(['ativo' => false]);
        $this->migrationQueCria();

        Artisan::spy();

        $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'segredo123']);

        $this->assertGuest();
        $this->assertFalse(Schema::hasTable('teste_migracao_automatica'));
        Artisan::shouldNotHaveReceived('call');
    }

    // ---- pendentes() -------------------------------------------------------

    public function test_pendentes_devolve_tudo_quando_a_tabela_migrations_nao_existe(): void
    {
        $servico = app(MigracaoAutomaticaService::class);
        $emDisco = array_map(
            fn (string $caminho) => basename($caminho, '.php'),
            glob(database_path('migrations/*_*.php')) ?: []
        );

        $this->assertNotEmpty($emDisco);
        $this->assertSame([], $servico->pendentes());

        Schema::drop('migrations');

        $this->assertEqualsCanonicalizing($emDisco, $servico->pendentes());
    }

    // ---- Trava -------------------------------------------------------------

    public function test_trava_ocupada_por_outro_processo_adia_a_migracao(): void
    {
        $this->migrationQueCria();

        $caminho = storage_path('app/migracao-automatica.lock');
        $this->temporarios[] = $caminho;
        $concorrente = fopen($caminho, 'c');
        $this->assertTrue(flock($concorrente, LOCK_EX | LOCK_NB));

        try {
            $resultado = app(MigracaoAutomaticaService::class)->executar();
        } finally {
            flock($concorrente, LOCK_UN);
            fclose($concorrente);
        }

        $this->assertSame([], $resultado['aplicadas']);
        $this->assertFalse(Schema::hasTable('teste_migracao_automatica'));
    }

    public function test_falha_ao_abrir_a_trava_nao_pode_cancelar_a_migracao(): void
    {
        // storage/app sem permissao de escrita e comum em deploy por FTP. Nao
        // conseguir travar nao e o mesmo que "outro processo ja esta migrando":
        // se isso cancelar a migracao o sistema fica em 500 para sempre.
        $this->migrationQueCria();

        $caminho = storage_path('app/migracao-automatica.lock');
        @unlink($caminho);
        mkdir($caminho);
        $this->temporarios[] = $caminho;

        $resultado = app(MigracaoAutomaticaService::class)->executar();

        $this->assertTrue($resultado['ok']);
        $this->assertTrue(Schema::hasTable('teste_migracao_automatica'));
    }

    // ---- Backup e identificacao do banco -----------------------------------

    /**
     * Roda executar() contra um banco SQLite em arquivo (o :memory: da suite nao
     * tem arquivo para copiar) e devolve o resultado junto dos backups criados.
     *
     * O nome da conexao e sempre proprio do teste: reaproveitar `sqlite` faria o
     * purge derrubar a conexao em memoria que a suite inteira usa.
     *
     * @return array{resultado: array<string, mixed>, novos: list<string>}
     */
    private function executarContraArquivo(string $conexao, string $driver): array
    {
        $banco = storage_path('app/teste-migracao-banco.sqlite');
        @unlink($banco);
        touch($banco);
        $this->temporarios[] = $banco;

        $antes = glob(storage_path('app/backups/banco-*.sqlite')) ?: [];

        config(["database.connections.{$conexao}" => [
            'driver' => $driver,
            'database' => $banco,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection($conexao);

        try {
            $resultado = app(MigracaoAutomaticaService::class)->executar();
        } finally {
            DB::setDefaultConnection($original);
            DB::purge($conexao);
        }

        $novos = array_values(array_diff(glob(storage_path('app/backups/banco-*.sqlite')) ?: [], $antes));

        foreach ($novos as $arquivo) {
            $this->temporarios[] = $arquivo;
        }

        return ['resultado' => $resultado, 'novos' => $novos];
    }

    public function test_executar_copia_o_banco_sqlite_para_a_pasta_de_backups(): void
    {
        ['resultado' => $resultado, 'novos' => $novos] = $this->executarContraArquivo('teste_backup', 'sqlite');

        $this->assertTrue($resultado['ok'], 'executar() deveria ter migrado o banco de arquivo');
        $this->assertCount(1, $novos, 'deveria existir exatamente um backup novo');
        $this->assertNotEmpty($resultado['aplicadas']);
    }

    public function test_mensagem_informa_sqlite_e_o_caminho_do_backup(): void
    {
        // O cliente nao tem o .env de producao: e por esta linha que ele descobre
        // qual banco o servidor usa e onde o backup foi parar.
        ['resultado' => $resultado, 'novos' => $novos] = $this->executarContraArquivo('teste_backup', 'sqlite');

        $this->assertStringContainsString('SQLite', $resultado['detalhe']);
        $this->assertStringContainsString('storage/app/backups/banco-', $resultado['detalhe']);
        $this->assertStringContainsString(basename($novos[0]), $resultado['detalhe']);
        $this->assertStringContainsString('migrations aplicadas', $resultado['mensagem']);

        // O detalhe fica fora da mensagem curta, que precisa caber no toast.
        $this->assertStringNotContainsString('backup', $resultado['mensagem']);
    }

    public function test_mensagem_informa_mysql_e_a_ausencia_de_backup_automatico(): void
    {
        // Conexao falsa: fala SQLite por baixo (para o migrate funcionar de
        // verdade) mas se identifica como mysql, que e o que o servico consulta.
        DB::extend('teste_backup_mysql', function (array $config, string $nome) {
            // O ConnectionFactory normalmente injeta o nome da conexao no config.
            // Sem ele o Migrator zera a conexao padrao no meio das migrations.
            $config['name'] = $nome;

            return new class(new PDO('sqlite:'.$config['database']), $config['database'], '', $config) extends SQLiteConnection
            {
                public function getDriverName(): string
                {
                    return 'mysql';
                }
            };
        });

        ['resultado' => $resultado, 'novos' => $novos] = $this->executarContraArquivo('teste_backup_mysql', 'mysql');

        $this->assertTrue($resultado['ok'], $resultado['mensagem']);
        $this->assertSame([], $novos, 'MySQL nao pode gerar copia de arquivo');
        $this->assertStringContainsString('MySQL', $resultado['detalhe']);
        $this->assertStringContainsString('sem backup automatico', $resultado['detalhe']);
        $this->assertStringContainsString('painel da hospedagem', $resultado['detalhe']);
        $this->assertStringNotContainsString('SQLite', $resultado['detalhe']);
    }

    public function test_mensagem_avisa_quando_o_backup_sqlite_falha(): void
    {
        // Pasta de backups intransitavel (um arquivo no lugar do diretorio): o
        // operador PRECISA saber que migrou sem rede de seguranca.
        $pasta = storage_path('app/backups');

        foreach (glob($pasta.'/*') ?: [] as $sobra) {
            @unlink($sobra);
        }

        @rmdir($pasta);
        $this->assertFalse(is_dir($pasta), 'nao foi possivel liberar a pasta de backups para o teste');

        touch($pasta);
        $this->temporarios[] = $pasta;

        ['resultado' => $resultado] = $this->executarContraArquivo('teste_backup', 'sqlite');

        $this->assertTrue($resultado['ok'], 'a falha de backup nao pode impedir a migracao');
        $this->assertStringContainsString('SQLite', $resultado['detalhe']);
        $this->assertStringContainsString('FALHOU', $resultado['detalhe']);
    }

    public function test_detalhe_do_banco_chega_na_sessao_junto_do_login(): void
    {
        $this->administrador();
        $this->migrationQueCria();

        $resposta = $this->post('/login', ['email' => 'admin@mmv.test', 'password' => 'segredo123']);

        $resposta->assertSessionHas('success');
        $resposta->assertSessionHas('toast_detalhe', fn (string $d) => str_contains($d, 'SQLite'));
    }
}
