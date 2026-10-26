<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No cadastro do perfil o Administrador escolhe quais usuarios recebem aquele perfil.
 * Cada usuario tem um perfil so: marcar move, desmarcar deixa sem perfil.
 */
class PerfilUsuariosTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $nome, ?Perfil $perfil): User
    {
        return User::create([
            'name' => $nome,
            'email' => strtolower($nome).'@mmv.test',
            'password' => 'segredo123',
            'perfil_id' => $perfil?->id,
            'ativo' => true,
        ]);
    }

    private function admin(): User
    {
        $perfil = Perfil::firstOrCreate(['nome' => Perfil::ADMINISTRADOR], ['permissoes' => ['admin' => 'editar'], 'ativo' => true]);

        return $this->usuario('Admin', $perfil);
    }

    public function test_cria_perfil_ja_com_usuarios(): void
    {
        $admin = $this->admin();
        $joao = $this->usuario('Joao', null);

        $this->actingAs($admin)->post(route('admin.perfis.store'), [
            'nome' => 'Compras', 'ativo' => 1,
            'permissoes' => ['cotacao' => 'editar'],
            'usuarios' => ['', $joao->id],
        ])->assertRedirect(route('admin.perfis.index'));

        $this->assertSame('Compras', $joao->fresh()->perfil->nome);
    }

    public function test_marcar_move_e_desmarcar_tira_do_perfil(): void
    {
        $admin = $this->admin();
        $vendas = Perfil::create(['nome' => 'Vendas', 'permissoes' => [], 'ativo' => true]);
        $compras = Perfil::create(['nome' => 'Compras', 'permissoes' => [], 'ativo' => true]);
        $ana = $this->usuario('Ana', $vendas);
        $bia = $this->usuario('Bia', $compras);

        $this->actingAs($admin)->put(route('admin.perfis.update', $compras), [
            'nome' => 'Compras', 'ativo' => 1, 'usuarios' => ['', $ana->id],
        ])->assertRedirect(route('admin.perfis.index'));

        $this->assertSame($compras->id, $ana->fresh()->perfil_id);
        $this->assertNull($bia->fresh()->perfil_id);
    }

    public function test_sem_a_lista_no_request_nao_mexe_nos_usuarios(): void
    {
        $admin = $this->admin();
        $compras = Perfil::create(['nome' => 'Compras', 'permissoes' => [], 'ativo' => true]);
        $bia = $this->usuario('Bia', $compras);

        $this->actingAs($admin)->put(route('admin.perfis.update', $compras), ['nome' => 'Compras', 'ativo' => 1]);

        $this->assertSame($compras->id, $bia->fresh()->perfil_id);
    }

    public function test_administrador_nao_se_tira_do_proprio_perfil(): void
    {
        $admin = $this->admin();
        $compras = Perfil::create(['nome' => 'Compras', 'permissoes' => [], 'ativo' => true]);

        $this->actingAs($admin)->put(route('admin.perfis.update', $admin->perfil), [
            'nome' => Perfil::ADMINISTRADOR, 'ativo' => 1, 'usuarios' => [''],
        ])->assertSessionHas('error');

        $this->actingAs($admin)->put(route('admin.perfis.update', $compras), [
            'nome' => 'Compras', 'ativo' => 1, 'usuarios' => ['', $admin->id],
        ])->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->ehAdministrador());
    }

    public function test_formulario_e_listagem_mostram_usuarios(): void
    {
        $admin = $this->admin();
        $compras = Perfil::create(['nome' => 'Compras', 'permissoes' => [], 'ativo' => true]);
        $this->usuario('Bia', $compras);

        $this->actingAs($admin)->get(route('admin.perfis.edit', $compras))
            ->assertOk()->assertSee('bia@mmv.test')->assertSee('hoje: Administrador');
        $this->actingAs($admin)->get(route('admin.perfis.index'))
            ->assertOk()->assertSee('Bia');
    }
}
