<?php

namespace Tests\Feature;

use App\Models\CategoriaComponente;
use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\Liberacao;
use App\Models\Material;
use App\Models\Perfil;
use App\Models\TipoComponente;
use App\Models\User;
use App\Services\OutputService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Duas regras que fazem o dado sair do Cadastro e chegar a folha de processo sem redigitacao:
 *
 * - Observacao livre por linha de material: gravada pelo endpoint, devolvida no JSON da tela
 *   e impressa em TODAS as secoes do PI (comprador e producao leem o mesmo papel).
 * - Especificacao completa do material: rotulo unico montado no model a partir de
 *   Categoria + Tipo + descricao + dimensoes + norma.
 */
class ObservacaoLinhaEspecificacaoMaterialTest extends TestCase
{
    use RefreshDatabase;

    private const OBS_EXEMPLO = 'fazer aproveitamento junto com a chapa X';

    private ?User $usuario = null;

    /** Mesmo usuario em todas as chamadas (email e unico na tabela). */
    private function usuario(): User
    {
        if ($this->usuario === null) {
            $perfil = Perfil::create([
                'nome' => 'Teste Engenharia',
                'permissoes' => ['engenharia' => 'editar'],
                'ativo' => true,
            ]);

            $this->usuario = User::create([
                'name' => 'Engenheiro',
                'email' => 'eng.obs@mmv.test',
                'password' => 'segredo123',
                'perfil_id' => $perfil->id,
                'ativo' => true,
            ]);
        }

        return $this->usuario;
    }

    /** Item de engenharia vindo de um PI: e o header que recebe as linhas de detalhamento. */
    private function item(string $numeroPi = 'PI-3001'): EngenhariaHeader
    {
        $cliente = Cliente::firstOrCreate(['nome' => 'Suzano SA'], ['ativo' => true]);
        $pi = Liberacao::create(['numero_pi' => $numeroPi, 'cliente_id' => $cliente->id]);
        $itemPi = $pi->itens()->create(['numero_item' => 1, 'descricao' => 'Rolo raspador', 'quantidade' => 1]);
        $demanda = Demanda::create(['tipo' => 'liberacao', 'referencia_id' => $pi->id]);

        return EngenhariaHeader::create([
            'demanda_id' => $demanda->id,
            'cliente_id' => $cliente->id,
            'numero_referencia' => $numeroPi,
            'nome_item' => 'Rolo raspador',
            'data_alocacao' => now()->toDateString(),
            'liberacao_item_id' => $itemPi->id,
            'responsavel_id' => $this->usuario()->id,
        ]);
    }

    /** Material do exemplo do cliente: "Chapa de aco — 1.200 × 6.000 — ASTM A36". */
    private function materialDoExemplo(array $sobrescrever = []): Material
    {
        $categoria = CategoriaComponente::firstOrCreate(['nome' => 'Aço', 'tipo' => 'materia_prima']);
        $tipo = TipoComponente::firstOrCreate(['categoria_id' => $categoria->id, 'nome' => 'Chapa']);

        return Material::create(array_merge([
            'tipo_id' => $tipo->id,
            'descricao' => 'ASTM A36',
            'dimensoes' => '1.200 × 6.000',
            'norma' => 'ASTM A36',
        ], $sobrescrever));
    }

    // ---- Entrega A: observacao por item de material ------------------------

    public function test_endpoint_grava_a_observacao_da_linha(): void
    {
        $header = $this->item();
        $this->actingAs($this->usuario());

        $this->postJson("/engenharia/{$header->id}/linha", [
            'descricao' => 'Corte da chapa',
            'tipo_componente' => 'materia_prima',
            'quantidade' => 2,
            'observacao' => self::OBS_EXEMPLO,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(self::OBS_EXEMPLO, $header->linhas()->firstOrFail()->observacao);
    }

    public function test_edicao_da_linha_atualiza_a_observacao(): void
    {
        $header = $this->item();
        $linha = $header->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte', 'observacao' => 'texto antigo']);
        $this->actingAs($this->usuario());

        $this->putJson("/engenharia/{$header->id}/linha/{$linha->id}", [
            'descricao' => 'Corte',
            'observacao' => self::OBS_EXEMPLO,
        ])->assertOk();

        $this->assertSame(self::OBS_EXEMPLO, $linha->fresh()->observacao);
    }

    public function test_json_de_linhas_devolve_a_observacao_para_a_tela(): void
    {
        $header = $this->item();
        $header->linhas()->create(['numero_linha' => 1, 'descricao' => 'Corte', 'observacao' => self::OBS_EXEMPLO]);
        $this->actingAs($this->usuario());

        $this->getJson("/engenharia/{$header->id}/linhas")
            ->assertOk()
            ->assertJsonPath('data.0.observacao', self::OBS_EXEMPLO);
    }

    public function test_observacao_sai_impressa_em_todas_as_secoes_do_pi(): void
    {
        $header = $this->item();

        // Uma linha por secao do PI; insumo e a linha sem tipo_componente.
        $secoes = [
            'materia_prima' => 'obs da materia prima',
            'servico' => 'obs da mao de obra',
            'comercial' => 'obs do componente comercial',
            null => 'obs do insumo',
        ];

        $numero = 0;
        foreach ($secoes as $tipo => $observacao) {
            $header->linhas()->create([
                'numero_linha' => ++$numero,
                'descricao' => 'Linha '.$numero,
                'tipo_componente' => $tipo ?: null,
                'quantidade' => 1,
                'observacao' => $observacao,
            ]);
        }

        $this->actingAs($this->usuario());
        $resposta = $this->get('/output/'.$header->demanda_id)->assertOk();

        foreach ($secoes as $observacao) {
            $resposta->assertSee($observacao);
        }
    }

    public function test_pdf_e_gerado_com_observacao_longa_sem_quebrar_a_tabela(): void
    {
        Storage::fake();

        $header = $this->item();
        // Texto longo de uma frase so: e o caso que estouraria a largura da celula no dompdf.
        $observacaoLonga = trim(str_repeat('aproveitar o retalho da chapa vizinha antes de cortar esta peca ', 12));
        $header->linhas()->create([
            'numero_linha' => 1,
            'descricao' => 'Corte da chapa',
            'tipo_componente' => 'materia_prima',
            'quantidade' => 1,
            'material_id' => $this->materialDoExemplo()->id,
            'observacao' => $observacaoLonga,
        ]);

        $output = app(OutputService::class)->gerarPdf($header->demanda, $this->usuario()->id);

        Storage::assertExists($output->path_pdf);
        $this->assertStringStartsWith('%PDF', Storage::get($output->path_pdf));
    }

    // ---- Entrega B: especificacao completa do material ---------------------

    public function test_acessor_monta_o_rotulo_do_exemplo_do_cliente(): void
    {
        $material = $this->materialDoExemplo();

        $this->assertSame(
            'Chapa de aço — 1.200 × 6.000 — ASTM A36',
            $material->especificacao_completa
        );
    }

    public function test_acessor_omite_partes_ausentes_sem_separador_solto(): void
    {
        $semDimensoesNemNorma = $this->materialDoExemplo(['dimensoes' => null, 'norma' => null]);

        $rotulo = $semDimensoesNemNorma->especificacao_completa;

        $this->assertSame('Chapa de aço — ASTM A36', $rotulo);
        $this->assertSame(1, substr_count($rotulo, Material::SEPARADOR));
        $this->assertStringNotContainsString('  ', $rotulo);
        $this->assertSame($rotulo, trim($rotulo, " \t\n\r—"));
    }

    public function test_acessor_com_apenas_categoria_e_tipo_nao_deixa_separador_nas_pontas(): void
    {
        $soIdentificacao = $this->materialDoExemplo(['descricao' => '', 'dimensoes' => null, 'norma' => null]);

        $this->assertSame('Chapa de aço', $soIdentificacao->especificacao_completa);
    }

    public function test_lookup_de_materiais_devolve_dimensoes_e_a_especificacao_completa(): void
    {
        $material = $this->materialDoExemplo();
        $this->actingAs($this->usuario());

        $this->getJson('/admin/lookup/materiais?tipo_id='.$material->tipo_id)
            ->assertOk()
            ->assertJsonPath('0.dimensoes', '1.200 × 6.000')
            ->assertJsonPath('0.especificacao', 'Chapa de aço — 1.200 × 6.000 — ASTM A36');
    }

    /**
     * O lookup mora em /admin/lookup/materiais justamente porque 'materiais' tambem e slug
     * do CRUD: registrado sem o prefixo, o CRUD sobrescrevia a rota e o dropdown recebia HTML.
     * Este teste prende as duas pontas.
     */
    public function test_crud_de_materiais_continua_respondendo_e_expoe_dimensoes(): void
    {
        $this->materialDoExemplo();
        $this->actingAs($this->usuario());

        $this->get('/admin/materiais')->assertOk()->assertSee('Dimensões');
        $this->get('/admin/materiais/create')->assertOk()->assertSee('Dimensões');
    }

    public function test_json_de_linhas_devolve_a_especificacao_completa_do_material(): void
    {
        $header = $this->item();
        $header->linhas()->create([
            'numero_linha' => 1,
            'descricao' => 'Corte da chapa',
            'material_id' => $this->materialDoExemplo()->id,
        ]);
        $this->actingAs($this->usuario());

        $this->getJson("/engenharia/{$header->id}/linhas")
            ->assertOk()
            ->assertJsonPath('data.0.material', 'Chapa de aço — 1.200 × 6.000 — ASTM A36');
    }

    public function test_pi_imprime_a_especificacao_completa_na_materia_prima(): void
    {
        $header = $this->item();
        $header->linhas()->create([
            'numero_linha' => 1,
            'descricao' => 'Corte da chapa',
            'tipo_componente' => 'materia_prima',
            'quantidade' => 1,
            'material_id' => $this->materialDoExemplo()->id,
        ]);
        $this->actingAs($this->usuario());

        $this->get('/output/'.$header->demanda_id)
            ->assertOk()
            ->assertSee('Chapa de aço — 1.200 × 6.000 — ASTM A36');
    }
}
