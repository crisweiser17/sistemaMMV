<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test da raiz do sistema.
 *
 * A rota "/" NUNCA devolve 200: em routes/web.php ela e um
 * `redirect()->route('demandas.index')` dentro do grupo ['auth', 'ativo'].
 * O teste padrao do Laravel (assertStatus(200)) era falso desde o inicio -
 * o que vale verificar e o destino do redirect em cada um dos dois casos.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_na_raiz_e_mandado_para_o_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_usuario_autenticado_na_raiz_cai_no_controle_de_demandas(): void
    {
        $perfil = Perfil::create(['nome' => 'Teste', 'permissoes' => [], 'ativo' => true]);
        $usuario = User::create([
            'name' => 'Raiz',
            'email' => 'raiz@mmv.test',
            'password' => 'segredo123',
            'perfil_id' => $perfil->id,
            'ativo' => true,
        ]);

        $this->actingAs($usuario)
            ->get('/')
            ->assertRedirect(route('demandas.index'));
    }
}
