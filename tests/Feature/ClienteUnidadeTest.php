<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ClienteUnidade;
use App\Models\Cotacao;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\User;
use App\Services\ClienteUnidadeService;
use App\Services\DemandaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Um mesmo cliente atende varias unidades (Suzano SA -> Tres Lagoas, Ribas do Rio Pardo).
 * Cobre a exibicao "Cliente – Unidade", o filtro por unidade e a conversao do
 * campo legado clientes.unidade sem perder o vinculo dos registros existentes.
 */
class ClienteUnidadeTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $permissoes = ['liberacao' => 'ver']): User
    {
        $perfil = Perfil::create(['nome' => 'Teste', 'permissoes' => $permissoes, 'ativo' => true]);

        return User::create([
            'name' => 'Comercial',
            'email' => 'comercial@mmv.test',
            'password' => 'segredo123',
            'perfil_id' => $perfil->id,
            'ativo' => true,
        ]);
    }

    /** @return array{0: Cliente, 1: ClienteUnidade, 2: ClienteUnidade} */
    private function clienteComDuasUnidades(): array
    {
        $cliente = Cliente::create(['nome' => 'Suzano SA', 'ativo' => true]);

        return [
            $cliente,
            $cliente->unidades()->create(['nome' => 'Tres Lagoas', 'ativo' => true]),
            $cliente->unidades()->create(['nome' => 'Ribas do Rio Pardo', 'ativo' => true]),
        ];
    }

    // ---- Exibicao ----------------------------------------------------------

    public function test_listagem_de_pi_mostra_cliente_com_a_unidade(): void
    {
        [$cliente, $tresLagoas, $ribas] = $this->clienteComDuasUnidades();
        Liberacao::create(['numero_pi' => 'PI-3001', 'cliente_id' => $cliente->id, 'unidade_id' => $tresLagoas->id]);
        Liberacao::create(['numero_pi' => 'PI-3002', 'cliente_id' => $cliente->id, 'unidade_id' => $ribas->id]);
        $this->actingAs($this->usuario());

        $resposta = $this->get('/liberacao');

        $resposta->assertOk()
            ->assertSee('Suzano SA'.Cliente::SEPARADOR_UNIDADE.'Tres Lagoas')
            ->assertSee('Suzano SA'.Cliente::SEPARADOR_UNIDADE.'Ribas do Rio Pardo');
    }

    public function test_pi_sem_unidade_mostra_so_o_nome_do_cliente(): void
    {
        $cliente = Cliente::create(['nome' => 'CMPC', 'ativo' => true]);
        Liberacao::create(['numero_pi' => 'PI-3003', 'cliente_id' => $cliente->id]);
        $this->actingAs($this->usuario());

        $this->get('/liberacao')
            ->assertOk()
            ->assertSee('CMPC')
            ->assertDontSee(Cliente::SEPARADOR_UNIDADE);
    }

    // ---- Filtro ------------------------------------------------------------

    public function test_filtro_por_unidade_devolve_so_os_pis_daquela_unidade(): void
    {
        [$cliente, $tresLagoas, $ribas] = $this->clienteComDuasUnidades();
        Liberacao::create(['numero_pi' => 'PI-3001', 'cliente_id' => $cliente->id, 'unidade_id' => $tresLagoas->id]);
        Liberacao::create(['numero_pi' => 'PI-3002', 'cliente_id' => $cliente->id, 'unidade_id' => $ribas->id]);
        $this->actingAs($this->usuario());

        $resposta = $this->get('/liberacao?cliente_id='.$cliente->id.'&unidade_id='.$tresLagoas->id);

        $resposta->assertOk()
            ->assertSee('PI-3001')
            ->assertDontSee('PI-3002')
            ->assertDontSee('Ribas do Rio Pardo');
    }

    public function test_grids_reativos_expoem_o_rotulo_com_unidade(): void
    {
        [$cliente, $tresLagoas] = $this->clienteComDuasUnidades();
        $usuario = $this->usuario(['demandas' => 'editar', 'engenharia' => 'editar']);
        $this->actingAs($usuario);

        $pi = Liberacao::create([
            'numero_pi' => 'PI-3010', 'cliente_id' => $cliente->id, 'unidade_id' => $tresLagoas->id,
        ]);
        $pi->itens()->create(['numero_item' => 1, 'descricao' => 'Eixo', 'quantidade' => 1]);
        $demanda = Demanda::create([
            'tipo' => 'liberacao', 'referencia_id' => $pi->id, 'data_entrada' => now()->toDateString(),
        ]);
        // A alocacao propaga a unidade do PI para os headers de engenharia.
        app(DemandaService::class)->alocar($demanda, $usuario->id);

        $rotulo = 'Suzano SA'.Cliente::SEPARADOR_UNIDADE.'Tres Lagoas';
        $this->getJson('/demandas/data')->assertOk()->assertJsonFragment(['cliente' => $rotulo]);
        $this->getJson('/engenharia/data')->assertOk()->assertJsonFragment(['cliente' => $rotulo]);
        $this->assertSame($tresLagoas->id, $demanda->headers()->first()->unidade_id);
    }

    // ---- Migracao do campo legado -----------------------------------------

    public function test_migracao_converte_o_texto_antigo_sem_perder_vinculo(): void
    {
        // Estado anterior a feature: unidade era texto solto no cliente.
        $cliente = Cliente::create(['nome' => 'CMPC', 'unidade' => 'Guaiba', 'ativo' => true]);
        $pi = Liberacao::create(['numero_pi' => 'PI-LEGADO', 'cliente_id' => $cliente->id]);
        $cotacao = Cotacao::create(['numero' => 'COT-LEGADO', 'cliente_id' => $cliente->id]);
        $demanda = Demanda::create(['tipo' => 'liberacao', 'referencia_id' => $pi->id]);
        $header = EngenhariaHeader::create([
            'demanda_id' => $demanda->id, 'cliente_id' => $cliente->id, 'nome_item' => 'Rolo',
        ]);

        $criadas = app(ClienteUnidadeService::class)->migrarLegado();

        $this->assertSame(1, $criadas);
        $unidade = ClienteUnidade::where('cliente_id', $cliente->id)->firstOrFail();
        $this->assertSame('Guaiba', $unidade->nome);
        $this->assertSame($unidade->id, $pi->fresh()->unidade_id);
        $this->assertSame($unidade->id, $cotacao->fresh()->unidade_id);
        $this->assertSame($unidade->id, $header->fresh()->unidade_id);
        $this->assertSame('CMPC'.Cliente::SEPARADOR_UNIDADE.'Guaiba', $pi->fresh()->cliente_com_unidade);
    }

    public function test_migracao_e_idempotente(): void
    {
        $cliente = Cliente::create(['nome' => 'CMPC', 'unidade' => 'Guaiba', 'ativo' => true]);
        $pi = Liberacao::create(['numero_pi' => 'PI-LEGADO', 'cliente_id' => $cliente->id]);
        $servico = app(ClienteUnidadeService::class);

        $servico->migrarLegado();
        $this->assertSame(0, $servico->migrarLegado(), 'Rodar de novo nao pode duplicar unidades.');
        $this->assertSame(1, ClienteUnidade::where('cliente_id', $cliente->id)->count());
        $this->assertNotNull($pi->fresh()->unidade_id);
    }

    // ---- Lookup JSON -------------------------------------------------------

    public function test_lookup_devolve_apenas_as_unidades_ativas_do_cliente(): void
    {
        [$cliente, $tresLagoas] = $this->clienteComDuasUnidades();
        $outro = Cliente::create(['nome' => 'CMPC', 'ativo' => true]);
        $outro->unidades()->create(['nome' => 'Guaiba', 'ativo' => true]);
        $cliente->unidades()->create(['nome' => 'Unidade desativada', 'ativo' => false]);
        $this->actingAs($this->usuario());

        $resposta = $this->getJson('/admin/lookup/unidades?cliente_id='.$cliente->id);

        $resposta->assertOk()->assertJsonCount(2);
        $nomes = array_column($resposta->json(), 'nome');
        $this->assertContains('Tres Lagoas', $nomes);
        $this->assertContains($tresLagoas->nome, $nomes);
        $this->assertNotContains('Guaiba', $nomes);
        $this->assertNotContains('Unidade desativada', $nomes);
    }
}
