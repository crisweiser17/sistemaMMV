<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ClienteUnidade;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\User;
use App\Services\ClienteUnidadeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * As unidades passaram a ser gerenciadas DENTRO do cadastro do cliente
 * (Cliente: CMPC | Unidade: Guaiba | Codigo: 24), num submit so, e o codigo
 * deixou de ser do cliente para ser de cada unidade.
 */
class ClienteComUnidadesTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        $perfil = Perfil::create(['nome' => 'Teste', 'permissoes' => ['admin' => 'editar'], 'ativo' => true]);

        return User::create([
            'name' => 'Cadastro',
            'email' => 'cadastro@mmv.test',
            'password' => 'segredo123',
            'perfil_id' => $perfil->id,
            'ativo' => true,
        ]);
    }

    /** Recria a coluna legada para exercitar a migracao de dados apos ela ja ter sido derrubada. */
    private function recriarCodigoPa(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('codigo_pa')->nullable();
        });
    }

    // ---- Cadastro do cliente com as unidades juntas ------------------------

    public function test_cria_cliente_com_duas_unidades_num_submit_so(): void
    {
        $this->actingAs($this->usuario());

        $resposta = $this->post('/admin/clientes', [
            'nome' => 'Suzano SA',
            'ativo' => '1',
            'unidades' => [
                ['id' => '', 'nome' => 'Tres Lagoas', 'codigo' => '10', 'ativo' => '1'],
                ['id' => '', 'nome' => 'Jacarei', 'codigo' => '25', 'ativo' => '1'],
            ],
        ]);

        $resposta->assertRedirect(route('admin.clientes.index'));
        $cliente = Cliente::where('nome', 'Suzano SA')->firstOrFail();
        $this->assertSame(2, $cliente->unidades()->count());
        $this->assertDatabaseHas('cliente_unidades', ['cliente_id' => $cliente->id, 'nome' => 'Tres Lagoas', 'codigo' => '10']);
        $this->assertDatabaseHas('cliente_unidades', ['cliente_id' => $cliente->id, 'nome' => 'Jacarei', 'codigo' => '25']);
    }

    public function test_formulario_de_novo_cliente_ja_abre_com_o_repeater_de_unidades(): void
    {
        $this->actingAs($this->usuario());

        $this->get('/admin/clientes/create')
            ->assertOk()
            ->assertSee('Novo Cliente')
            ->assertSee('Adicionar Unidade')
            ->assertDontSee('Código PA');
    }

    public function test_formulario_do_cliente_mostra_as_unidades_para_edicao(): void
    {
        $cliente = Cliente::create(['nome' => 'CMPC', 'ativo' => true]);
        $cliente->unidades()->create(['nome' => 'Guaiba', 'codigo' => '24', 'ativo' => true]);
        $this->actingAs($this->usuario());

        $this->get('/admin/clientes/'.$cliente->id.'/edit')
            ->assertOk()
            ->assertSee('Unidades')
            ->assertSee('Adicionar Unidade')
            ->assertSee('Guaiba')
            ->assertSee('24');
    }

    public function test_editar_o_nome_da_unidade_nao_troca_o_id(): void
    {
        $cliente = Cliente::create(['nome' => 'CMPC', 'ativo' => true]);
        $unidade = $cliente->unidades()->create(['nome' => 'Guaiba', 'codigo' => '24', 'ativo' => true]);
        $this->actingAs($this->usuario());

        $resposta = $this->put('/admin/clientes/'.$cliente->id, [
            'nome' => 'CMPC',
            'ativo' => '1',
            'unidades' => [
                ['id' => (string) $unidade->id, 'nome' => 'Guaíba (RS)', 'codigo' => '24', 'ativo' => '1'],
            ],
        ]);

        $resposta->assertRedirect(route('admin.clientes.index'));
        $this->assertSame(1, $cliente->unidades()->count(), 'Editar nao pode recriar a unidade.');
        $atualizada = $unidade->fresh();
        $this->assertSame($unidade->id, $atualizada->id);
        $this->assertSame('Guaíba (RS)', $atualizada->nome);
    }

    public function test_unidade_pode_ser_desativada_pelo_formulario(): void
    {
        $cliente = Cliente::create(['nome' => 'CMPC', 'ativo' => true]);
        $unidade = $cliente->unidades()->create(['nome' => 'Guaiba', 'codigo' => '24', 'ativo' => true]);
        $this->actingAs($this->usuario());

        $this->put('/admin/clientes/'.$cliente->id, [
            'nome' => 'CMPC',
            'ativo' => '1',
            'unidades' => [['id' => (string) $unidade->id, 'nome' => 'Guaiba', 'codigo' => '24', 'ativo' => '0']],
        ])->assertRedirect(route('admin.clientes.index'));

        $this->assertFalse($unidade->fresh()->ativo);
    }

    // ---- Remocao ----------------------------------------------------------

    public function test_remove_unidade_sem_vinculo(): void
    {
        $cliente = Cliente::create(['nome' => 'Suzano SA', 'ativo' => true]);
        $fica = $cliente->unidades()->create(['nome' => 'Tres Lagoas', 'codigo' => '10', 'ativo' => true]);
        $sai = $cliente->unidades()->create(['nome' => 'Jacarei', 'codigo' => '25', 'ativo' => true]);
        $this->actingAs($this->usuario());

        $resposta = $this->put('/admin/clientes/'.$cliente->id, [
            'nome' => 'Suzano SA',
            'ativo' => '1',
            'unidades' => [['id' => (string) $fica->id, 'nome' => 'Tres Lagoas', 'codigo' => '10', 'ativo' => '1']],
        ]);

        $resposta->assertRedirect(route('admin.clientes.index'))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('cliente_unidades', ['id' => $sai->id]);
        $this->assertSame(1, $cliente->unidades()->count());
    }

    public function test_remocao_de_unidade_com_vinculo_e_bloqueada_com_a_contagem(): void
    {
        $cliente = Cliente::create(['nome' => 'Suzano SA', 'ativo' => true]);
        $fica = $cliente->unidades()->create(['nome' => 'Tres Lagoas', 'codigo' => '10', 'ativo' => true]);
        $usada = $cliente->unidades()->create(['nome' => 'Jacarei', 'codigo' => '25', 'ativo' => true]);
        Liberacao::create(['numero_pi' => 'PI-9101', 'cliente_id' => $cliente->id, 'unidade_id' => $usada->id]);
        $this->actingAs($this->usuario());

        $resposta = $this->put('/admin/clientes/'.$cliente->id, [
            'nome' => 'Suzano Papel',
            'ativo' => '1',
            'unidades' => [['id' => (string) $fica->id, 'nome' => 'Tres Lagoas', 'codigo' => '10', 'ativo' => '1']],
        ]);

        $resposta->assertSessionHasErrors('unidades');
        $erro = session('errors')->first('unidades');
        $this->assertStringContainsString('Jacarei', $erro);
        $this->assertStringContainsString('1 registro(s)', $erro);
        $this->assertStringContainsString('Ativo', $erro);

        // Nada muda no submit barrado: nem a unidade, nem o nome do cliente.
        $this->assertNotSoftDeleted('cliente_unidades', ['id' => $usada->id]);
        $this->assertSame(2, $cliente->unidades()->count());
        $this->assertSame('Suzano SA', $cliente->fresh()->nome);
    }

    // ---- Hub e lookup -----------------------------------------------------

    public function test_hub_admin_nao_lista_mais_o_card_de_unidades_de_cliente(): void
    {
        $this->actingAs($this->usuario());

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Clientes')
            ->assertDontSee('Unidades de Cliente');
    }

    public function test_rotas_do_crud_generico_de_unidades_de_cliente_nao_existem_mais(): void
    {
        $this->assertFalse(app('router')->has('admin.unidades-cliente.index'));
        $this->actingAs($this->usuario());

        $this->get('/admin/unidades-cliente')->assertNotFound();
    }

    public function test_lookup_de_unidades_continua_respondendo(): void
    {
        $cliente = Cliente::create(['nome' => 'CMPC', 'ativo' => true]);
        $cliente->unidades()->create(['nome' => 'Guaiba', 'codigo' => '24', 'ativo' => true]);
        $this->actingAs($this->usuario());

        $this->getJson('/admin/lookup/unidades?cliente_id='.$cliente->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['nome' => 'Guaiba', 'codigo' => '24']);
    }

    // ---- Migracao do codigo_pa --------------------------------------------

    public function test_migracao_move_o_codigo_quando_o_cliente_tem_uma_unidade_so(): void
    {
        $this->recriarCodigoPa();
        $cliente = Cliente::create(['nome' => 'CMPC', 'ativo' => true]);
        $unidade = $cliente->unidades()->create(['nome' => 'Guaiba', 'ativo' => true]);
        DB::table('clientes')->where('id', $cliente->id)->update(['codigo_pa' => '24']);

        $resultado = app(ClienteUnidadeService::class)->moverCodigoPaParaUnidades();

        $this->assertSame(1, $resultado['movidos']);
        $this->assertSame([], $resultado['pendentes']);
        $this->assertSame('24', $unidade->fresh()->codigo);
    }

    public function test_migracao_nao_adivinha_quando_o_cliente_tem_varias_unidades(): void
    {
        $this->recriarCodigoPa();
        $cliente = Cliente::create(['nome' => 'Suzano SA', 'ativo' => true]);
        $tresLagoas = $cliente->unidades()->create(['nome' => 'Tres Lagoas', 'ativo' => true]);
        $jacarei = $cliente->unidades()->create(['nome' => 'Jacarei', 'codigo' => '25', 'ativo' => true]);
        DB::table('clientes')->where('id', $cliente->id)->update(['codigo_pa' => 'PA-004']);

        $resultado = app(ClienteUnidadeService::class)->moverCodigoPaParaUnidades();

        $this->assertSame(0, $resultado['movidos']);
        $this->assertCount(1, $resultado['pendentes']);
        $this->assertStringContainsString('Suzano SA', $resultado['pendentes'][0]);
        $this->assertNull($tresLagoas->fresh()->codigo);
        $this->assertSame('25', $jacarei->fresh()->codigo);
    }

    public function test_migracao_nao_sobrescreve_codigo_ja_preenchido_e_e_idempotente(): void
    {
        $this->recriarCodigoPa();
        $cliente = Cliente::create(['nome' => 'CMPC', 'ativo' => true]);
        $unidade = $cliente->unidades()->create(['nome' => 'Guaiba', 'codigo' => '24', 'ativo' => true]);
        DB::table('clientes')->where('id', $cliente->id)->update(['codigo_pa' => 'PA-005']);
        $servico = app(ClienteUnidadeService::class);

        $servico->moverCodigoPaParaUnidades();
        $segunda = $servico->moverCodigoPaParaUnidades();

        $this->assertSame(0, $segunda['movidos']);
        $this->assertSame('24', $unidade->fresh()->codigo);
    }

    public function test_migracao_e_no_op_depois_que_a_coluna_saiu(): void
    {
        // Estado real do banco apos a migration: a coluna nao existe mais.
        $this->assertFalse(Schema::hasColumn('clientes', 'codigo_pa'));

        $resultado = app(ClienteUnidadeService::class)->moverCodigoPaParaUnidades();

        $this->assertSame(['movidos' => 0, 'pendentes' => []], $resultado);
    }

    public function test_unidade_nova_sem_codigo_fica_com_codigo_nulo(): void
    {
        $this->actingAs($this->usuario());

        $this->post('/admin/clientes', [
            'nome' => 'Klabin',
            'ativo' => '1',
            'unidades' => [['id' => '', 'nome' => 'Ortigueira', 'codigo' => '', 'ativo' => '1']],
        ])->assertRedirect(route('admin.clientes.index'));

        $unidade = ClienteUnidade::where('nome', 'Ortigueira')->firstOrFail();
        $this->assertNull($unidade->codigo);
    }
}
