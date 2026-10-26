<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Usuarios (quem faz login e aparece como responsavel nas demandas) sao cadastrados
 * pelo Administrador em Cadastros de Apoio. Perfil e so o conjunto de permissoes.
 */
class CadastroUsuariosTest extends TestCase
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

    private function admin(): User
    {
        return $this->usuario('Administrador', ['admin' => 'editar', 'demandas' => 'editar']);
    }

    public function test_administrador_cria_usuario_que_aparece_como_responsavel(): void
    {
        $admin = $this->admin();
        $engenharia = Perfil::create(['nome' => 'Engenharia', 'permissoes' => ['demandas' => 'editar'], 'ativo' => true]);

        $this->actingAs($admin)->post(route('admin.usuarios.store'), [
            'name' => 'Edison Fraulin',
            'email' => 'edison@mmv.test',
            'password' => 'senhaforte1',
            'perfil_id' => $engenharia->id,
            'ativo' => 1,
        ])->assertRedirect(route('admin.usuarios.index'));

        $edison = User::where('email', 'edison@mmv.test')->firstOrFail();
        $this->assertSame($engenharia->id, $edison->perfil_id);
        $this->assertNotSame('senhaforte1', $edison->getRawOriginal('password'));
        $this->assertTrue(Hash::check('senhaforte1', $edison->password));

        $this->actingAs($admin)->get(route('demandas.index'))->assertOk()->assertSee('Edison Fraulin');
    }

    public function test_senha_e_obrigatoria_na_criacao(): void
    {
        $this->actingAs($this->admin())->post(route('admin.usuarios.store'), [
            'name' => 'Sem Senha',
            'email' => 'semsenha@mmv.test',
            'perfil_id' => Perfil::first()->id,
        ])->assertSessionHasErrors('password');
    }

    public function test_senha_em_branco_na_edicao_mantem_a_atual(): void
    {
        $admin = $this->admin();
        $outro = $this->usuario('Engenharia', ['demandas' => 'editar']);

        $this->actingAs($admin)->put(route('admin.usuarios.update', $outro->id), [
            'name' => 'Engenharia Renomeado',
            'email' => $outro->email,
            'password' => '',
            'perfil_id' => $outro->perfil_id,
            'ativo' => 1,
        ])->assertRedirect(route('admin.usuarios.index'));

        $outro->refresh();
        $this->assertSame('Engenharia Renomeado', $outro->name);
        $this->assertTrue(Hash::check('segredo123', $outro->password));
    }

    public function test_email_repetido_e_rejeitado(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.usuarios.store'), [
            'name' => 'Copia',
            'email' => $admin->email,
            'password' => 'senhaforte1',
            'perfil_id' => $admin->perfil_id,
        ])->assertSessionHasErrors('email');
    }

    public function test_formulario_nao_expoe_a_senha_gravada(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.usuarios.edit', $admin->id))
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertDontSee($admin->getRawOriginal('password'), false);
    }

    public function test_administrador_nao_se_tranca_para_fora(): void
    {
        $admin = $this->admin();
        $consulta = Perfil::create(['nome' => 'Consulta', 'permissoes' => ['demandas' => 'ver'], 'ativo' => true]);
        $base = ['name' => $admin->name, 'email' => $admin->email, 'perfil_id' => $admin->perfil_id, 'ativo' => 1];

        $this->actingAs($admin)->put(route('admin.usuarios.update', $admin->id), ['ativo' => 0] + $base)
            ->assertSessionHas('error');
        $this->actingAs($admin)->put(route('admin.usuarios.update', $admin->id), ['perfil_id' => $consulta->id] + $base)
            ->assertSessionHas('error');
        $this->actingAs($admin)->delete(route('admin.usuarios.destroy', $admin->id))
            ->assertSessionHas('error');

        $admin->refresh();
        $this->assertTrue($admin->ativo);
        $this->assertTrue($admin->ehAdministrador());
        $this->assertNull($admin->deleted_at);
    }

    public function test_quem_nao_e_administrador_nao_cadastra_usuario(): void
    {
        $eng = $this->usuario('Engenharia', ['admin' => 'editar']);

        $this->actingAs($eng)->get(route('admin.usuarios.create'))->assertForbidden();
        $this->actingAs($eng)->post(route('admin.usuarios.store'), [
            'name' => 'Intruso',
            'email' => 'intruso@mmv.test',
            'password' => 'senhaforte1',
            'perfil_id' => $eng->perfil_id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'intruso@mmv.test']);
    }
}
