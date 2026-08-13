<?php

namespace Tests\Feature;

use App\Models\CategoriaComponente;
use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\Escopo;
use App\Models\Liberacao;
use App\Models\Material;
use App\Models\Perfil;
use App\Models\StatusEngenharia;
use App\Models\TipoComponente;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Services\EngenhariaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quando uma peca igual chega em outro pedido, a engenharia copia a estrutura do
 * item ja concluido em vez de redigitar. Cobre o que a copia leva (campos e
 * dependencias remapeadas), o que ela deixa para tras (anexo e status da linha)
 * e quais itens podem servir de molde.
 */
class CopiaEstruturaEngenhariaTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $permissoes = ['engenharia' => 'editar']): User
    {
        $perfil = Perfil::create(['nome' => 'Teste', 'permissoes' => $permissoes, 'ativo' => true]);

        return User::create([
            'name' => 'Engenheiro',
            'email' => 'eng@mmv.test',
            'password' => 'segredo123',
            'perfil_id' => $perfil->id,
            'ativo' => true,
        ]);
    }

    private function statusEngenharia(string $nome): StatusEngenharia
    {
        return StatusEngenharia::firstOrCreate(['nome' => $nome]);
    }

    /** Item de engenharia vindo de um PI, com o item de origem preenchido (cod. MMV/NI). */
    private function item(string $numeroPi, string $nomeItem, ?string $status, array $dadosItem = []): EngenhariaHeader
    {
        $cliente = Cliente::firstOrCreate(['nome' => 'Suzano SA'], ['ativo' => true]);
        $pi = Liberacao::create(['numero_pi' => $numeroPi, 'cliente_id' => $cliente->id]);
        $itemPi = $pi->itens()->create(array_merge(
            ['numero_item' => 1, 'descricao' => $nomeItem, 'quantidade' => 1],
            $dadosItem
        ));
        $demanda = Demanda::create(['tipo' => 'liberacao', 'referencia_id' => $pi->id]);

        return EngenhariaHeader::create([
            'demanda_id' => $demanda->id,
            'cliente_id' => $cliente->id,
            'numero_referencia' => $numeroPi,
            'nome_item' => $nomeItem,
            'data_alocacao' => now()->toDateString(),
            'liberacao_item_id' => $itemPi->id,
            'status_id' => $status ? $this->statusEngenharia($status)->id : null,
        ]);
    }

    private function servico(): EngenhariaService
    {
        return app(EngenhariaService::class);
    }

    // ---- O que a copia leva -----------------------------------------------

    public function test_copia_preserva_os_campos_de_negocio_e_descarta_anexo_e_status(): void
    {
        $escopo = Escopo::create(['descricao' => 'Fabricacao']);
        $unidade = UnidadeMedida::create(['sigla' => 'PC']);
        $categoria = CategoriaComponente::create(['nome' => 'Chapas', 'tipo' => 'materia_prima']);
        $tipo = TipoComponente::create(['categoria_id' => $categoria->id, 'nome' => 'Aco carbono']);
        $material = Material::create(['tipo_id' => $tipo->id, 'descricao' => 'ASTM A36']);

        $origem = $this->item('PI-1001', 'Rolo raspador', 'Finalizado');
        $campos = [
            'numero_linha' => 1,
            'descricao' => 'Corte da chapa',
            'cod_mmv' => 'MMV-77',
            'local_referencia' => 'Setor B',
            'escopo_id' => $escopo->id,
            'tipo_componente' => 'materia_prima',
            'categoria_componente_id' => $categoria->id,
            'tipo_componente_id' => $tipo->id,
            'material_id' => $material->id,
            'mao_de_obra' => 'Caldeireiro',
            'quantidade' => 4.5,
            'unidade_id' => $unidade->id,
            'observacao' => 'Conferir espessura',
            'fase' => 'Usinagem',
            'duracao_dias' => 3,
        ];
        $origem->linhas()->create(array_merge($campos, [
            'arquivo_path' => 'engenharia/1/1/desenho.pdf',
            'status' => 'Concluida',
        ]));

        $destino = $this->item('PI-2002', 'Rolo raspador', 'A iniciar');
        $this->statusEngenharia('Em andamento');

        $copiadas = $this->servico()->copiarEstrutura($destino, $origem);

        $this->assertSame(1, $copiadas);
        $copia = $destino->linhas()->firstOrFail();
        foreach ($campos as $campo => $esperado) {
            $this->assertEquals($esperado, $copia->{$campo}, "Campo {$campo} nao veio na copia.");
        }
        $this->assertNull($copia->arquivo_path, 'O anexo da linha de origem nao pode vir junto.');
        $this->assertNull($copia->status, 'A copia nasce sem status.');
        $this->assertSame($destino->id, $copia->header_id);
        // A linha de origem segue intacta: a copia nunca reaproveita o registro antigo.
        $this->assertSame('engenharia/1/1/desenho.pdf', $origem->linhas()->firstOrFail()->arquivo_path);
        $this->assertSame('Em andamento', $destino->fresh()->status->nome);
    }

    public function test_copia_remapeia_dependencias_para_as_linhas_novas(): void
    {
        $origem = $this->item('PI-1001', 'Eixo', 'Finalizado');
        $l1 = $origem->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);
        $l2 = $origem->linhas()->create(['numero_linha' => 2, 'descricao' => 'Solda']);
        $l3 = $origem->linhas()->create(['numero_linha' => 3, 'descricao' => 'Pintura']);
        $l2->dependencias()->attach($l1->id);
        $l3->dependencias()->attach([$l1->id, $l2->id]);

        $destino = $this->item('PI-2002', 'Eixo', 'A iniciar');

        $this->servico()->copiarEstrutura($destino, $origem);

        $copias = $destino->linhas()->with('dependencias')->get()->keyBy('numero_linha');
        $idsAntigos = [$l1->id, $l2->id, $l3->id];
        $this->assertSame([], $copias[1]->dependencias->pluck('id')->all());
        $this->assertSame([$copias[1]->id], $copias[2]->dependencias->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$copias[1]->id, $copias[2]->id],
            $copias[3]->dependencias->pluck('id')->all()
        );
        foreach ($copias as $copia) {
            foreach ($copia->dependencias as $dependencia) {
                $this->assertNotContains($dependencia->id, $idsAntigos, 'Dependencia ficou apontando para a linha antiga.');
            }
        }
        // As dependencias do item de origem continuam como estavam.
        $this->assertSame([$l1->id], $l2->fresh()->dependencias->pluck('id')->all());
    }

    // ---- Modos de copia ----------------------------------------------------

    public function test_modo_acrescentar_renumera_as_linhas_sem_deixar_buraco(): void
    {
        $origem = $this->item('PI-1001', 'Mancal', 'Finalizado');
        $origem->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);
        $origem->linhas()->create(['numero_linha' => 2, 'descricao' => 'Solda']);

        // Exclusoes anteriores deixaram um buraco na numeracao do item de destino.
        $destino = $this->item('PI-2002', 'Mancal', 'Em andamento');
        $destino->linhas()->create(['numero_linha' => 1, 'descricao' => 'Existente A']);
        $destino->linhas()->create(['numero_linha' => 5, 'descricao' => 'Existente B']);

        $copiadas = $this->servico()->copiarEstrutura($destino, $origem, EngenhariaService::MODO_ACRESCENTAR);

        $linhas = $destino->linhas()->get();
        $this->assertSame(2, $copiadas);
        $this->assertSame([1, 2, 3, 4], $linhas->pluck('numero_linha')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(
            ['Existente A', 'Existente B', 'Corte', 'Solda'],
            $linhas->pluck('descricao')->all()
        );
    }

    public function test_modo_substituir_limpa_as_linhas_atuais_antes_de_copiar(): void
    {
        $origem = $this->item('PI-1001', 'Mancal', 'Finalizado');
        $origem->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);
        $origem->linhas()->create(['numero_linha' => 2, 'descricao' => 'Solda']);

        $destino = $this->item('PI-2002', 'Mancal', 'Em andamento');
        $antiga = $destino->linhas()->create(['numero_linha' => 1, 'descricao' => 'Existente A']);
        $destino->linhas()->create(['numero_linha' => 2, 'descricao' => 'Existente B']);

        $copiadas = $this->servico()->copiarEstrutura($destino, $origem, EngenhariaService::MODO_SUBSTITUIR);

        $linhas = $destino->linhas()->get();
        $this->assertSame(2, $copiadas);
        $this->assertSame(['Corte', 'Solda'], $linhas->pluck('descricao')->all());
        $this->assertSame([1, 2], $linhas->pluck('numero_linha')->map(fn ($n) => (int) $n)->all());
        $this->assertNull(EngenhariaLinha::find($antiga->id), 'A linha antiga deveria ter sido removida.');
    }

    // ---- Busca de estruturas ----------------------------------------------

    public function test_busca_lista_so_itens_concluidos_com_linhas_e_fora_do_item_atual(): void
    {
        $concluido = $this->item('PI-1001', 'Rolo raspador', 'Finalizado', ['cod_mmv' => 'MMV-77', 'ni' => 'NI-500']);
        $concluido->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);
        $concluido->linhas()->create(['numero_linha' => 2, 'descricao' => 'Solda']);

        $emAndamento = $this->item('PI-1002', 'Rolo em andamento', 'Em andamento');
        $emAndamento->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);

        $concluidoSemLinhas = $this->item('PI-1003', 'Rolo sem estrutura', 'Finalizado');

        $destino = $this->item('PI-2002', 'Rolo raspador', 'Finalizado');
        $destino->linhas()->create(['numero_linha' => 1, 'descricao' => 'Existente']);
        $this->actingAs($this->usuario());

        $resposta = $this->getJson("/engenharia/{$destino->id}/estruturas");

        $resposta->assertOk();
        $ids = array_column($resposta->json('data'), 'id');
        $this->assertContains($concluido->id, $ids);
        $this->assertNotContains($emAndamento->id, $ids, 'Item nao concluido nao pode aparecer na busca.');
        $this->assertNotContains($concluidoSemLinhas->id, $ids, 'Item sem linhas nao tem estrutura a copiar.');
        $this->assertNotContains($destino->id, $ids, 'O proprio item nao e origem de copia.');
        $resposta->assertJsonFragment([
            'id' => $concluido->id,
            'numero_referencia' => 'PI-1001',
            'nome_item' => 'Rolo raspador',
            'cod_mmv' => 'MMV-77',
            'ni' => 'NI-500',
            'cliente' => 'Suzano SA',
            'linhas' => 2,
        ]);
    }

    public function test_busca_encontra_por_codigo_mmv_ni_e_descricao_do_item(): void
    {
        $rolo = $this->item('PI-1001', 'Rolo raspador', 'Finalizado', ['cod_mmv' => 'MMV-77', 'ni' => 'NI-500', 'descricao' => 'Rolo raspador']);
        $rolo->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);
        $eixo = $this->item('PI-1002', 'Eixo', 'Finalizado', ['cod_mmv' => 'MMV-99', 'ni' => 'NI-900', 'descricao' => 'Eixo']);
        $eixo->linhas()->create(['numero_linha' => 1, 'descricao' => 'Torneamento']);

        $destino = $this->item('PI-2002', 'Rolo raspador', 'A iniciar');
        $this->actingAs($this->usuario());

        foreach (['MMV-77', 'NI-500', 'raspador'] as $termo) {
            $ids = array_column($this->getJson("/engenharia/{$destino->id}/estruturas?busca={$termo}")->json('data'), 'id');
            $this->assertSame([$rolo->id], $ids, "Busca por '{$termo}' devolveu o item errado.");
        }
    }

    // ---- Endpoint de copia -------------------------------------------------

    public function test_endpoint_copia_a_estrutura_e_devolve_quantas_linhas_vieram(): void
    {
        $origem = $this->item('PI-1001', 'Rolo raspador', 'Finalizado');
        $origem->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);
        $origem->linhas()->create(['numero_linha' => 2, 'descricao' => 'Solda']);
        $destino = $this->item('PI-2002', 'Rolo raspador', 'A iniciar');
        $this->actingAs($this->usuario());

        $resposta = $this->postJson("/engenharia/{$destino->id}/copiar-estrutura", [
            'origem_id' => $origem->id,
            'modo' => EngenhariaService::MODO_ACRESCENTAR,
        ]);

        $resposta->assertOk()->assertJson(['ok' => true, 'linhas' => 2]);
        $this->assertSame(2, $destino->linhas()->count());
    }

    public function test_endpoint_recusa_origem_nao_concluida(): void
    {
        $origem = $this->item('PI-1001', 'Rolo raspador', 'Em andamento');
        $origem->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);
        $destino = $this->item('PI-2002', 'Rolo raspador', 'A iniciar');
        $this->actingAs($this->usuario());

        $resposta = $this->postJson("/engenharia/{$destino->id}/copiar-estrutura", [
            'origem_id' => $origem->id,
            'modo' => EngenhariaService::MODO_ACRESCENTAR,
        ]);

        $resposta->assertStatus(422)->assertJsonValidationErrors('origem_id');
        $this->assertSame(0, $destino->linhas()->count());
    }

    public function test_endpoint_exige_permissao_de_edicao(): void
    {
        $origem = $this->item('PI-1001', 'Rolo raspador', 'Finalizado');
        $origem->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte']);
        $destino = $this->item('PI-2002', 'Rolo raspador', 'A iniciar');
        $this->actingAs($this->usuario(['engenharia' => 'ver']));

        $this->postJson("/engenharia/{$destino->id}/copiar-estrutura", [
            'origem_id' => $origem->id,
            'modo' => EngenhariaService::MODO_ACRESCENTAR,
        ])->assertForbidden();

        $this->assertSame(0, $destino->linhas()->count());
    }
}
