<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\User;
use App\Services\LiberacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NF em dois niveis: o PI tem a NF do pedido e cada item pode ter a sua (itens de
 * recuperacao chegam em remessas diferentes). Cobre a heranca item -> PI, a
 * sobrescrita pelo item e a busca da listagem por qualquer uma das duas.
 */
class LiberacaoNotaFiscalTest extends TestCase
{
    use RefreshDatabase;

    private ?User $usuario = null;

    /** Mesmo usuario em todas as chamadas do teste (email e unico na tabela). */
    private function usuario(): User
    {
        if ($this->usuario === null) {
            $perfil = Perfil::create([
                'nome' => 'Teste NF', 'permissoes' => ['liberacao' => 'editar'], 'ativo' => true,
            ]);

            $this->usuario = User::create([
                'name' => 'Comercial',
                'email' => 'comercial.nf@mmv.test',
                'password' => 'segredo123',
                'perfil_id' => $perfil->id,
                'ativo' => true,
            ]);
        }

        return $this->usuario;
    }

    /** PI com NF no cabecalho e dois itens: um com NF propria, outro sem. */
    private function piComItens(?string $nfPi, ?string $nfItem1, ?string $nfItem2 = null): Liberacao
    {
        $cliente = Cliente::create(['nome' => 'Vale S.A.', 'ativo' => true]);

        return app(LiberacaoService::class)->criar(
            ['numero_pi' => 'PI-NF-01', 'cliente_id' => $cliente->id, 'nf_cliente' => $nfPi],
            [
                ['numero_item' => 1, 'descricao' => 'Eixo principal', 'quantidade' => 1, 'nf_cliente' => $nfItem1],
                ['numero_item' => 2, 'descricao' => 'Mancal bipartido', 'quantidade' => 2, 'nf_cliente' => $nfItem2],
            ],
            $this->usuario()->id
        );
    }

    // ---- Regra de negocio --------------------------------------------------

    public function test_nf_do_item_sobrescreve_a_nf_do_pi(): void
    {
        $pi = $this->piComItens('NF-1000', 'NF-2000');

        $item = $pi->itens()->where('numero_item', 1)->firstOrFail();

        $this->assertSame('NF-2000', $item->nf_cliente);
        $this->assertSame('NF-2000', $item->nf_efetiva);
        $this->assertTrue($item->nf_diverge_do_pi);
    }

    public function test_item_sem_nf_herda_a_nf_do_pi(): void
    {
        $pi = $this->piComItens('NF-1000', 'NF-2000');

        $item = $pi->itens()->where('numero_item', 2)->firstOrFail();

        $this->assertNull($item->nf_cliente);
        $this->assertSame('NF-1000', $item->nf_efetiva);
        $this->assertFalse($item->nf_diverge_do_pi);
    }

    public function test_item_sem_nf_em_pi_sem_nf_fica_sem_nf(): void
    {
        $pi = $this->piComItens(null, null);

        $this->assertNull($pi->itens()->where('numero_item', 1)->firstOrFail()->nf_efetiva);
    }

    public function test_resumo_da_listagem_conta_apenas_as_nfs_divergentes(): void
    {
        $pi = $this->piComItens('NF-1000', 'NF-2000', 'NF-3000')->load('itens');

        $resumo = $pi->resumoNf();

        $this->assertSame('NF-1000', $resumo['rotulo']);
        $this->assertSame(2, $resumo['extras']);
        $this->assertStringContainsString('NF-2000', $resumo['detalhe']);
        $this->assertStringContainsString('NF-3000', $resumo['detalhe']);
    }

    // ---- Formulario --------------------------------------------------------

    public function test_formulario_salva_a_nf_do_pi_e_a_nf_de_cada_item(): void
    {
        $cliente = Cliente::create(['nome' => 'CSN', 'ativo' => true]);
        $this->actingAs($this->usuario());

        $resposta = $this->post('/liberacao', [
            'numero_pi' => 'PI-NF-99',
            'cliente_id' => $cliente->id,
            'nf_cliente' => 'NF-5000',
            'itens' => [
                ['numero_item' => 1, 'descricao' => 'Rolo de mesa', 'quantidade' => 3, 'nf_cliente' => 'NF-5001'],
                ['numero_item' => 2, 'descricao' => 'Eixo do rolo', 'quantidade' => 3, 'nf_cliente' => ''],
            ],
        ]);

        $resposta->assertRedirect();
        $pi = Liberacao::where('numero_pi', 'PI-NF-99')->firstOrFail();
        $this->assertSame('NF-5000', $pi->nf_cliente);
        $this->assertSame('NF-5001', $pi->itens()->where('numero_item', 1)->value('nf_cliente'));
        $this->assertSame('NF-5000', $pi->itens()->where('numero_item', 2)->firstOrFail()->nf_efetiva);
    }

    // ---- Busca -------------------------------------------------------------

    public function test_busca_acha_o_pi_pela_nf_do_cabecalho(): void
    {
        $this->piComItens('NF-1000', 'NF-2000');
        $this->actingAs($this->usuario());

        $this->get('/liberacao?busca=NF-1000')->assertOk()->assertSee('PI-NF-01');
    }

    public function test_busca_acha_o_pi_pela_nf_de_um_item(): void
    {
        $this->piComItens('NF-1000', 'NF-2000');
        $this->actingAs($this->usuario());

        $this->get('/liberacao?busca=NF-2000')->assertOk()->assertSee('PI-NF-01');
    }

    public function test_busca_por_nf_inexistente_nao_traz_o_pi(): void
    {
        $this->piComItens('NF-1000', 'NF-2000');
        $this->actingAs($this->usuario());

        $this->get('/liberacao?busca=NF-9999')->assertOk()->assertDontSee('PI-NF-01');
    }

    public function test_busca_continua_achando_pelo_numero_do_pi(): void
    {
        $this->piComItens('NF-1000', 'NF-2000');
        $this->actingAs($this->usuario());

        $this->get('/liberacao?busca=PI-NF-01')->assertOk()->assertSee('PI-NF-01');
    }

    // ---- Telas -------------------------------------------------------------

    public function test_listagem_mostra_a_nf_do_pi_com_o_contador_de_divergentes(): void
    {
        $this->piComItens('NF-1000', 'NF-2000');
        $this->actingAs($this->usuario());

        $this->get('/liberacao')->assertOk()->assertSee('NF-1000')->assertSee('+1');
    }

    public function test_tela_do_pi_mostra_a_nf_efetiva_de_cada_item(): void
    {
        $pi = $this->piComItens('NF-1000', 'NF-2000');
        $this->actingAs($this->usuario());

        $this->get('/liberacao/'.$pi->id)
            ->assertOk()
            ->assertSee('NF-2000')   // item 1: NF propria
            ->assertSee('NF-1000');  // item 2: herdada do PI
    }
}
