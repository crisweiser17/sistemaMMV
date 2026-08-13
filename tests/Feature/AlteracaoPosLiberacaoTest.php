<?php

namespace Tests\Feature;

use App\Events\ProcessoAlterado;
use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\Liberacao;
use App\Models\Output;
use App\Models\Perfil;
use App\Models\StatusEngenharia;
use App\Models\User;
use App\Services\AlteracaoService;
use App\Services\EngenhariaService;
use App\Services\OutputService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Processo ja liberado que muda (ex.: quantidade de chapa de 3 para 4) precisa ser
 * marcado, explicado e avisado. O marco e a geracao do ULTIMO PDF do PI: antes dele
 * nada e alteracao, e um PDF novo zera a marcacao.
 */
class AlteracaoPosLiberacaoTest extends TestCase
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

    /** Item de engenharia vindo de um PI, com uma linha de detalhamento. */
    private function item(string $numeroPi = 'PI-1173'): EngenhariaHeader
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
            'status_id' => StatusEngenharia::firstOrCreate(['nome' => 'Em andamento'])->id,
        ]);
    }

    private function linha(EngenhariaHeader $header, float $quantidade = 3): EngenhariaLinha
    {
        return $header->linhas()->create([
            'numero_linha' => 1,
            'descricao' => 'Chapa de aço',
            'tipo_componente' => 'materia_prima',
            'quantidade' => $quantidade,
        ]);
    }

    /** Registra a liberacao (geracao do PDF) no instante atual. */
    private function liberarPdf(Demanda $demanda): Output
    {
        return Output::create([
            'demanda_id' => $demanda->id,
            'gerado_por' => null,
            'gerado_em' => now(),
            'path_pdf' => 'outputs/demanda/'.$demanda->id.'/teste.pdf',
        ]);
    }

    private function servico(): AlteracaoService
    {
        return app(AlteracaoService::class);
    }

    // ---- Antes da liberacao ------------------------------------------------

    public function test_alteracao_antes_do_primeiro_pdf_nao_marca_nada(): void
    {
        $header = $this->item();
        $linha = $this->linha($header, 3);

        $this->travel(10)->minutes();
        $linha->update(['quantidade' => 4]);

        $demanda = $header->demanda;
        $this->assertFalse($this->servico()->houveAlteracao($demanda));
        $this->assertFalse($this->servico()->mapa($demanda)->ativo());
        $this->assertSame([], $this->servico()->historico($demanda)['eventos']);
    }

    public function test_nenhum_aviso_e_disparado_enquanto_o_processo_nao_foi_liberado(): void
    {
        Event::fake([ProcessoAlterado::class]);
        $header = $this->item();
        $linha = $this->linha($header, 3);

        app(EngenhariaService::class)->atualizarLinha($linha, ['quantidade' => 4]);

        Event::assertNotDispatched(ProcessoAlterado::class);
    }

    // ---- Depois da liberacao -----------------------------------------------

    public function test_alteracao_de_quantidade_depois_do_pdf_marca_e_registra_valor_anterior_e_novo(): void
    {
        $header = $this->item();
        $linha = $this->linha($header, 3);
        $demanda = $header->demanda;
        $this->liberarPdf($demanda);

        $this->travel(10)->minutes();
        $this->actingAs($this->usuario());
        $linha->update(['quantidade' => 4]);

        $this->assertTrue($this->servico()->houveAlteracao($demanda));

        $mapa = $this->servico()->mapa($demanda);
        $this->assertTrue($mapa->ativo());
        $this->assertSame(['de' => '3', 'para' => '4'], $mapa->campo($linha->fresh(), 'quantidade'));

        $eventos = $this->servico()->historico($demanda)['eventos'];
        $this->assertCount(1, $eventos);
        $this->assertSame('Quantidade', $eventos[0]['campo']);
        $this->assertSame('3', $eventos[0]['de']);
        $this->assertSame('4', $eventos[0]['para']);
        $this->assertSame('Engenheiro', $eventos[0]['usuario']);
        $this->assertSame('Linha 1 — Chapa de aço', $eventos[0]['registro']);
        $this->assertNotNull($eventos[0]['data']);
    }

    public function test_linha_criada_depois_do_pdf_conta_como_alteracao(): void
    {
        $header = $this->item();
        $this->linha($header, 3);
        $demanda = $header->demanda;
        $this->liberarPdf($demanda);

        $this->travel(10)->minutes();
        $nova = $header->linhas()->create(['numero_linha' => 2, 'descricao' => 'Parafuso sextavado', 'quantidade' => 8]);

        $mapa = $this->servico()->mapa($demanda);
        $this->assertTrue($this->servico()->houveAlteracao($demanda));
        $this->assertTrue($mapa->novo($nova), 'A linha criada depois do PDF deveria estar marcada como nova.');

        $eventos = $this->servico()->historico($demanda)['eventos'];
        $this->assertCount(1, $eventos);
        $this->assertSame('Criado', $eventos[0]['evento']);
        $this->assertSame('Linha 2 — Parafuso sextavado', $eventos[0]['registro']);
    }

    public function test_linha_excluida_depois_do_pdf_conta_como_alteracao(): void
    {
        $header = $this->item();
        $linha = $this->linha($header, 3);
        $demanda = $header->demanda;
        $this->liberarPdf($demanda);

        $this->travel(10)->minutes();
        $linha->delete();

        $mapa = $this->servico()->mapa($demanda);
        $this->assertTrue($this->servico()->houveAlteracao($demanda));
        $this->assertSame(['Linha 1 — Chapa de aço'], $mapa->excluidos());

        $eventos = $this->servico()->historico($demanda)['eventos'];
        $this->assertCount(1, $eventos);
        $this->assertSame('Excluído', $eventos[0]['evento']);
    }

    public function test_mudanca_de_status_do_item_nao_marca_o_processo(): void
    {
        $header = $this->item();
        $this->linha($header, 3);
        $demanda = $header->demanda;
        $this->liberarPdf($demanda);

        $this->travel(10)->minutes();
        app(EngenhariaService::class)->finalizar($header);

        $this->assertFalse(
            $this->servico()->houveAlteracao($demanda),
            'Finalizar o item e fluxo de trabalho, nao mudanca de conteudo do processo.'
        );
    }

    // ---- Nova liberacao zera a marcacao ------------------------------------

    public function test_gerar_pdf_novo_limpa_a_marcacao(): void
    {
        Storage::fake();
        $header = $this->item();
        $linha = $this->linha($header, 3);
        $demanda = $header->demanda;
        $this->liberarPdf($demanda);

        $this->travel(10)->minutes();
        $linha->update(['quantidade' => 4]);
        $this->assertTrue($this->servico()->houveAlteracao($demanda));

        // O PDF novo passa a ser a versao vigente do processo.
        $this->travel(10)->minutes();
        app(OutputService::class)->gerarPdf($demanda, $this->usuario()->id);

        $this->assertFalse($this->servico()->houveAlteracao($demanda));
        $this->assertFalse($this->servico()->mapa($demanda)->ativo());
        $this->assertSame([], $this->servico()->historico($demanda)['eventos']);
    }

    // ---- Aviso a producao e compras ----------------------------------------

    public function test_alteracao_pos_pdf_dispara_o_aviso_com_o_numero_do_processo(): void
    {
        Event::fake([ProcessoAlterado::class]);
        $header = $this->item('1173');
        $linha = $this->linha($header, 3);
        $this->liberarPdf($header->demanda);

        $this->travel(10)->minutes();
        app(EngenhariaService::class)->atualizarLinha($linha, ['quantidade' => 4]);

        Event::assertDispatched(
            ProcessoAlterado::class,
            fn (ProcessoAlterado $e) => $e->numeroReferencia === '1173'
                && $e->broadcastWith()['mensagem'] === 'Houve alteração no processo 1173'
        );
    }

    // ---- Marcador nas listagens --------------------------------------------

    public function test_listagem_de_demandas_expoe_o_marcador_de_alteracao(): void
    {
        $header = $this->item();
        $linha = $this->linha($header, 3);
        $this->liberarPdf($header->demanda);

        $this->travel(10)->minutes();
        $linha->update(['quantidade' => 4]);
        $this->actingAs($this->usuario(['demandas' => 'ver', 'engenharia' => 'ver']));

        $this->getJson('/demandas/data')
            ->assertOk()
            ->assertJsonPath('data.0.alterado', true);
    }

    public function test_tela_de_historico_lista_campo_valores_data_e_usuario(): void
    {
        $header = $this->item();
        $linha = $this->linha($header, 3);
        $demanda = $header->demanda;
        $this->liberarPdf($demanda);

        $this->travel(10)->minutes();
        $this->actingAs($this->usuario());
        $linha->update(['quantidade' => 4]);

        $this->get("/output/{$demanda->id}/alteracoes")
            ->assertOk()
            ->assertSee('Linha 1 — Chapa de aço')
            ->assertSee('Quantidade')
            ->assertSee('Engenheiro');
    }

    public function test_tela_de_engenharia_oferece_o_atalho_para_as_alteracoes(): void
    {
        $header = $this->item();
        $linha = $this->linha($header, 3);
        $demanda = $header->demanda;
        $usuario = $this->usuario();
        $header->update(['responsavel_id' => $usuario->id]);
        $this->liberarPdf($demanda);

        $this->travel(10)->minutes();
        $linha->update(['quantidade' => 4]);

        $this->actingAs($usuario)
            ->get("/engenharia/demanda/{$demanda->id}")
            ->assertOk()
            ->assertSee(route('output.alteracoes', $demanda));
    }

    public function test_preview_destaca_em_vermelho_e_o_pdf_sai_limpo(): void
    {
        Storage::fake();
        $header = $this->item();
        $linha = $this->linha($header, 3);
        $demanda = $header->demanda;
        $this->liberarPdf($demanda);

        $this->travel(10)->minutes();
        $linha->update(['quantidade' => 4]);
        $this->actingAs($this->usuario());

        // Preview: vermelho + valor anterior visivel ao lado.
        $this->get('/output/'.$demanda->id)
            ->assertOk()
            ->assertSee('Processo alterado após a liberação')
            ->assertSee('(antes: 3)');

        // O mesmo dado montado para o PDF nao carrega marcacao nenhuma.
        $this->assertFalse(app(OutputService::class)->montarDados($demanda)['alteracoes']->ativo());
    }
}
