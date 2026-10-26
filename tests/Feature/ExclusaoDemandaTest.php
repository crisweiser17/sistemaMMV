<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Excluir demanda e exclusivo do Administrador: leva junto o PI/cotacao de origem
 * e os headers de engenharia (soft delete), liberando o numero para novo lancamento.
 */
class ExclusaoDemandaTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $perfil): User
    {
        $todos = ['demandas' => 'editar', 'liberacao' => 'editar', 'cotacao' => 'editar', 'engenharia' => 'editar'];

        return User::create([
            'name' => $perfil,
            'email' => strtolower(str_replace(' ', '.', $perfil)).'@mmv.test',
            'password' => 'segredo123',
            'perfil_id' => Perfil::create(['nome' => $perfil, 'permissoes' => $todos, 'ativo' => true])->id,
            'ativo' => true,
        ]);
    }

    /** Lanca um PI pela tela (gera a demanda) e aloca, para existir header de engenharia. */
    private function demandaAlocada(User $usuario, string $numero = '1167'): Demanda
    {
        $cliente = Cliente::create(['nome' => 'Cliente '.$numero, 'ativo' => true]);
        $this->actingAs($usuario)->post('/liberacao', [
            'numero_pi' => $numero,
            'cliente_id' => $cliente->id,
            'itens' => [['numero_item' => 1, 'descricao' => 'Eixo principal', 'quantidade' => 1]],
        ])->assertSessionHasNoErrors();

        $demanda = Demanda::where('tipo', 'liberacao')->latest('id')->firstOrFail();
        $this->putJson("/demandas/{$demanda->id}/alocar", ['responsavel_id' => $usuario->id])->assertOk();

        return $demanda;
    }

    public function test_administrador_exclui_demanda_com_pi_e_headers(): void
    {
        $admin = $this->usuario(Perfil::ADMINISTRADOR);
        $demanda = $this->demandaAlocada($admin);
        $this->assertSame(1, $demanda->headers()->count());

        $this->deleteJson("/demandas/{$demanda->id}")->assertOk();

        $this->assertSoftDeleted('demandas', ['id' => $demanda->id]);
        $this->assertSoftDeleted('liberacoes', ['id' => $demanda->referencia_id]);
        $this->assertSame(0, $demanda->headers()->count());
        $this->assertSame([], $this->getJson('/demandas/data')->json('data'));
    }

    public function test_numero_do_pi_excluido_pode_ser_lancado_de_novo(): void
    {
        $admin = $this->usuario(Perfil::ADMINISTRADOR);
        $demanda = $this->demandaAlocada($admin);
        $this->deleteJson("/demandas/{$demanda->id}")->assertOk();

        $this->demandaAlocada($admin);

        $this->assertSame(1, Liberacao::where('numero_pi', '1167')->count());
    }

    public function test_quem_nao_e_administrador_nao_exclui_mesmo_podendo_editar(): void
    {
        $comercial = $this->usuario('Comercial');
        $demanda = $this->demandaAlocada($comercial);

        $this->deleteJson("/demandas/{$demanda->id}")->assertForbidden();

        $this->assertNotSoftDeleted('demandas', ['id' => $demanda->id]);
    }
}
