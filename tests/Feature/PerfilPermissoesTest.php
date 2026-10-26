<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permissoes do perfil saem de uma grade "Sem acesso / Ver / Editar" (nao mais JSON
 * digitado) e so o Administrador cria, edita ou remove perfis.
 */
class PerfilPermissoesTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $perfil, array $permissoes = []): User
    {
        $p = Perfil::firstOrCreate(['nome' => $perfil], ['permissoes' => $permissoes, 'ativo' => true]);

        return User::create([
            'name' => $perfil,
            'email' => strtolower($perfil).'@mmv.test',
            'password' => 'segredo123',
            'perfil_id' => $p->id,
            'ativo' => true,
        ]);
    }

    public function test_administrador_grava_permissoes_pela_grade(): void
    {
        $admin = $this->usuario('Administrador', ['admin' => 'editar']);

        $this->actingAs($admin)->post(route('admin.perfis.store'), [
            'nome' => 'Compras',
            'ativo' => 1,
            'permissoes' => ['cotacao' => 'editar', 'engenharia' => 'ver', 'demandas' => 'nenhum', 'inventado' => 'editar'],
        ])->assertRedirect(route('admin.perfis.index'));

        $perfil = Perfil::where('nome', 'Compras')->firstOrFail();
        $this->assertSame(['cotacao' => 'editar', 'engenharia' => 'ver'], $perfil->permissoes);
    }

    public function test_nivel_invalido_e_rejeitado(): void
    {
        $admin = $this->usuario('Administrador', ['admin' => 'editar']);

        $this->actingAs($admin)->post(route('admin.perfis.store'), [
            'nome' => 'Compras',
            'permissoes' => ['cotacao' => 'tudo'],
        ])->assertSessionHasErrors('permissoes.cotacao');
    }

    public function test_formulario_mostra_grade_e_nao_textarea_json(): void
    {
        $admin = $this->usuario('Administrador', ['admin' => 'editar']);

        $this->actingAs($admin)->get(route('admin.perfis.edit', $admin->perfil_id))
            ->assertOk()
            ->assertSee('name="permissoes[cotacao]"', false)
            ->assertSee('Sem acesso')
            ->assertDontSee('<textarea name="permissoes"', false);
    }

    public function test_quem_nao_e_administrador_so_visualiza(): void
    {
        $eng = $this->usuario('Engenharia', ['admin' => 'editar']);
        $outro = Perfil::create(['nome' => 'Consulta', 'permissoes' => ['cotacao' => 'ver'], 'ativo' => true]);

        $this->actingAs($eng)->get(route('admin.perfis.index'))
            ->assertOk()->assertSee('Somente leitura')->assertDontSee('+ Novo Perfil');
        $this->actingAs($eng)->get(route('admin.perfis.create'))->assertForbidden();
        $this->actingAs($eng)->get(route('admin.perfis.edit', $outro->id))->assertForbidden();
        $this->actingAs($eng)->put(route('admin.perfis.update', $outro->id), [
            'nome' => 'Consulta', 'permissoes' => ['admin' => 'editar'],
        ])->assertForbidden();
        $this->actingAs($eng)->delete(route('admin.perfis.destroy', $outro->id))->assertForbidden();

        $this->assertSame(['cotacao' => 'ver'], $outro->fresh()->permissoes);
    }

    public function test_perfil_administrador_nao_pode_ser_renomeado_nem_removido(): void
    {
        $admin = $this->usuario('Administrador', ['admin' => 'editar']);

        $this->actingAs($admin)->put(route('admin.perfis.update', $admin->perfil_id), [
            'nome' => 'Chefe', 'ativo' => 1, 'permissoes' => ['admin' => 'editar'],
        ])->assertRedirect();
        $this->assertSame('Administrador', $admin->perfil->fresh()->nome);

        $this->actingAs($admin)->delete(route('admin.perfis.destroy', $admin->perfil_id));
        $this->assertNotNull(Perfil::find($admin->perfil_id));
    }
}
