<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotacao;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Services\AnexoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Item de PI/cotacao editado pela tela precisa chegar inteiro no service.
 *
 * O controller monta o payload com $request->validate(), que devolve SO as chaves
 * com regra declarada: campo de item sem regra e descartado em silencio e o service
 * grava null no lugar. O caso mais caro e o 'id' — sem ele a sincronizacao cria um
 * registro novo e apaga o antigo, levando junto os anexos do item e o historico de
 * auditoria que a marcacao de alteracao pos-liberacao usa como fonte.
 */
class EdicaoItensPedidoTest extends TestCase
{
    use RefreshDatabase;

    private ?User $usuario = null;

    /** Mesmo usuario em todas as chamadas do teste (email e unico na tabela). */
    private function usuario(): User
    {
        if ($this->usuario === null) {
            $perfil = Perfil::create([
                'nome' => 'Comercial Teste',
                'permissoes' => ['liberacao' => 'editar', 'cotacao' => 'editar'],
                'ativo' => true,
            ]);

            $this->usuario = User::create([
                'name' => 'Comercial',
                'email' => 'comercial.itens@mmv.test',
                'password' => 'segredo123',
                'perfil_id' => $perfil->id,
                'ativo' => true,
            ]);
        }

        return $this->usuario;
    }

    private function cliente(): Cliente
    {
        return Cliente::firstOrCreate(['nome' => 'Klabin SA'], ['ativo' => true]);
    }

    private function unidade(string $sigla = 'PC'): UnidadeMedida
    {
        return UnidadeMedida::firstOrCreate(['sigla' => $sigla], ['descricao' => $sigla, 'ativo' => true]);
    }

    /**
     * Item completo como a tela envia: todos os campos que o service grava.
     *
     * @return array<string, mixed>
     */
    private function itemCompleto(array $sobrescrever = []): array
    {
        return array_merge([
            'numero_item' => 1,
            'cod_mmv' => 'MMV-4711',
            'ni' => 'NI-0099',
            'descricao' => 'Rolo raspador',
            'quantidade' => '2',
            'unidade_id' => $this->unidade()->id,
            'material_cliente' => 'Aco inox 316',
            'nf_cliente' => 'NF-777',
            'prazo_entrega_item' => 30,
            'descricao_cliente' => 'Rolo conforme desenho do cliente',
            'observacoes' => 'Entregar com laudo dimensional',
        ], $sobrescrever);
    }

    /** Cria o PI pela tela (POST) e devolve o registro persistido. */
    private function criarPi(array $itens): Liberacao
    {
        $this->actingAs($this->usuario())
            ->post('/liberacao', [
                'numero_pi' => 'PI-2201',
                'cliente_id' => $this->cliente()->id,
                'nf_cliente' => 'NF-100',
                'itens' => $itens,
            ])
            ->assertRedirect();

        return Liberacao::latest('id')->firstOrFail();
    }

    /** Cria a cotacao pela tela (POST) e devolve o registro persistido. */
    private function criarCotacao(array $itens): Cotacao
    {
        $this->actingAs($this->usuario())
            ->post('/cotacao', [
                'numero' => 'COT-2201',
                'cliente_id' => $this->cliente()->id,
                'itens' => $itens,
            ])
            ->assertRedirect();

        return Cotacao::latest('id')->firstOrFail();
    }

    // ---- PI: criacao -------------------------------------------------------

    public function test_criacao_de_pi_persiste_todos_os_campos_do_item(): void
    {
        $pi = $this->criarPi([$this->itemCompleto()]);

        $item = $pi->itens()->firstOrFail();

        $this->assertSame(1, (int) $item->numero_item);
        $this->assertSame('MMV-4711', $item->cod_mmv);
        $this->assertSame('NI-0099', $item->ni);
        $this->assertSame('Rolo raspador', $item->descricao);
        $this->assertSame('2.000', (string) $item->quantidade);
        $this->assertSame($this->unidade()->id, $item->unidade_id);
        $this->assertSame('Aco inox 316', $item->material_cliente);
        $this->assertSame('NF-777', $item->nf_cliente);
        $this->assertSame(30, $item->prazo_entrega_item);
        $this->assertSame('Rolo conforme desenho do cliente', $item->descricao_cliente);
        $this->assertSame('Entregar com laudo dimensional', $item->observacoes);
    }

    // ---- PI: edicao --------------------------------------------------------

    public function test_edicao_de_pi_atualiza_o_item_no_lugar_sem_trocar_o_id(): void
    {
        $pi = $this->criarPi([$this->itemCompleto()]);
        $idOriginal = $pi->itens()->value('id');

        $this->actingAs($this->usuario())
            ->put("/liberacao/{$pi->id}", [
                'numero_pi' => 'PI-2201',
                'cliente_id' => $this->cliente()->id,
                'itens' => [$this->itemCompleto([
                    'id' => $idOriginal,
                    'cod_mmv' => 'MMV-9000',
                    'ni' => 'NI-1234',
                    'material_cliente' => 'Aco carbono',
                ])],
            ])
            ->assertRedirect();

        $itens = $pi->fresh()->itens;

        $this->assertCount(1, $itens, 'A edicao nao pode multiplicar os itens do PI.');
        $this->assertSame($idOriginal, $itens[0]->id, 'O item foi recriado em vez de atualizado.');
        $this->assertSame('MMV-9000', $itens[0]->cod_mmv);
        $this->assertSame('NI-1234', $itens[0]->ni);
        $this->assertSame('Aco carbono', $itens[0]->material_cliente);
        // Campos nao tocados na edicao continuam de pe.
        $this->assertSame('Rolo conforme desenho do cliente', $itens[0]->descricao_cliente);
        $this->assertSame('Entregar com laudo dimensional', $itens[0]->observacoes);
    }

    /**
     * O teste que mais importa: com o 'id' descartado, o service recriava o item e
     * o anexo ficava pendurado num registro apagado.
     */
    public function test_anexo_do_item_sobrevive_a_edicao_do_pi(): void
    {
        Storage::fake(AnexoService::DISCO);
        $pi = $this->criarPi([$this->itemCompleto()]);
        $item = $pi->itens()->firstOrFail();

        $this->actingAs($this->usuario())
            ->post("/liberacao/{$pi->id}/item/{$item->id}/anexo", [
                'arquivo' => UploadedFile::fake()->create('desenho.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $anexoId = $item->anexos()->value('id');
        $this->assertNotNull($anexoId);

        $this->actingAs($this->usuario())
            ->put("/liberacao/{$pi->id}", [
                'numero_pi' => 'PI-2201',
                'cliente_id' => $this->cliente()->id,
                'itens' => [$this->itemCompleto(['id' => $item->id, 'quantidade' => '5'])],
            ])
            ->assertRedirect();

        $itemDepois = $pi->fresh()->itens->firstOrFail();

        $this->assertSame($item->id, $itemDepois->id, 'O item recriado deixaria o anexo orfao.');
        $this->assertSame('5.000', (string) $itemDepois->quantidade);
        $this->assertSame([$anexoId], $itemDepois->anexos()->pluck('id')->all());
    }

    public function test_remover_um_item_do_pi_preserva_os_ids_dos_demais(): void
    {
        $pi = $this->criarPi([
            $this->itemCompleto(['numero_item' => 1, 'cod_mmv' => 'MMV-1', 'descricao' => 'Item um']),
            $this->itemCompleto(['numero_item' => 2, 'cod_mmv' => 'MMV-2', 'descricao' => 'Item dois']),
            $this->itemCompleto(['numero_item' => 3, 'cod_mmv' => 'MMV-3', 'descricao' => 'Item tres']),
        ]);

        $ids = $pi->itens()->orderBy('numero_item')->pluck('id', 'cod_mmv');
        $this->assertCount(3, $ids);

        // Tela envia so o que sobrou, renumerado — o item do meio saiu.
        $this->actingAs($this->usuario())
            ->put("/liberacao/{$pi->id}", [
                'numero_pi' => 'PI-2201',
                'cliente_id' => $this->cliente()->id,
                'itens' => [
                    $this->itemCompleto(['id' => $ids['MMV-1'], 'numero_item' => 1, 'cod_mmv' => 'MMV-1', 'descricao' => 'Item um']),
                    $this->itemCompleto(['id' => $ids['MMV-3'], 'numero_item' => 2, 'cod_mmv' => 'MMV-3', 'descricao' => 'Item tres']),
                ],
            ])
            ->assertRedirect();

        $restantes = $pi->fresh()->itens->sortBy('numero_item')->values();

        $this->assertCount(2, $restantes);
        $this->assertSame([$ids['MMV-1'], $ids['MMV-3']], $restantes->pluck('id')->all());
        $this->assertSame(['MMV-1', 'MMV-3'], $restantes->pluck('cod_mmv')->all());
        $this->assertNull($pi->itens()->whereKey($ids['MMV-2'])->first());
    }

    // ---- Cotacao -----------------------------------------------------------

    public function test_criacao_de_cotacao_persiste_todos_os_campos_do_item(): void
    {
        // A cotacao nao tem NF nem prazo por item: o service so grava os outros nove campos.
        $cotacao = $this->criarCotacao([
            $this->itemCompleto(['nf_cliente' => null, 'prazo_entrega_item' => null]),
        ]);

        $item = $cotacao->itens()->firstOrFail();

        $this->assertSame(1, (int) $item->numero_item);
        $this->assertSame('MMV-4711', $item->cod_mmv);
        $this->assertSame('NI-0099', $item->ni);
        $this->assertSame('Rolo raspador', $item->descricao);
        $this->assertSame('2.000', (string) $item->quantidade);
        $this->assertSame($this->unidade()->id, $item->unidade_id);
        $this->assertSame('Aco inox 316', $item->material_cliente);
        $this->assertSame('Rolo conforme desenho do cliente', $item->descricao_cliente);
        $this->assertSame('Entregar com laudo dimensional', $item->observacoes);
    }

    public function test_edicao_de_cotacao_atualiza_o_item_no_lugar_sem_trocar_o_id(): void
    {
        $cotacao = $this->criarCotacao([$this->itemCompleto(['nf_cliente' => null, 'prazo_entrega_item' => null])]);
        $idOriginal = $cotacao->itens()->value('id');

        $this->actingAs($this->usuario())
            ->put("/cotacao/{$cotacao->id}", [
                'numero' => 'COT-2201',
                'cliente_id' => $this->cliente()->id,
                'itens' => [$this->itemCompleto([
                    'id' => $idOriginal,
                    'nf_cliente' => null,
                    'prazo_entrega_item' => null,
                    'cod_mmv' => 'MMV-9000',
                    'ni' => 'NI-1234',
                    'material_cliente' => 'Aco carbono',
                ])],
            ])
            ->assertRedirect();

        $itens = $cotacao->fresh()->itens;

        $this->assertCount(1, $itens, 'A edicao nao pode multiplicar os itens da cotacao.');
        $this->assertSame($idOriginal, $itens[0]->id, 'O item foi recriado em vez de atualizado.');
        $this->assertSame('MMV-9000', $itens[0]->cod_mmv);
        $this->assertSame('NI-1234', $itens[0]->ni);
        $this->assertSame('Aco carbono', $itens[0]->material_cliente);
        $this->assertSame('Rolo conforme desenho do cliente', $itens[0]->descricao_cliente);
        $this->assertSame('Entregar com laudo dimensional', $itens[0]->observacoes);
    }

    public function test_remover_um_item_da_cotacao_preserva_os_ids_dos_demais(): void
    {
        $cotacao = $this->criarCotacao([
            $this->itemCompleto(['numero_item' => 1, 'cod_mmv' => 'MMV-1', 'nf_cliente' => null, 'prazo_entrega_item' => null]),
            $this->itemCompleto(['numero_item' => 2, 'cod_mmv' => 'MMV-2', 'nf_cliente' => null, 'prazo_entrega_item' => null]),
        ]);

        $ids = $cotacao->itens()->orderBy('numero_item')->pluck('id', 'cod_mmv');

        $this->actingAs($this->usuario())
            ->put("/cotacao/{$cotacao->id}", [
                'numero' => 'COT-2201',
                'cliente_id' => $this->cliente()->id,
                'itens' => [
                    $this->itemCompleto(['id' => $ids['MMV-2'], 'numero_item' => 1, 'cod_mmv' => 'MMV-2', 'nf_cliente' => null, 'prazo_entrega_item' => null]),
                ],
            ])
            ->assertRedirect();

        $restantes = $cotacao->fresh()->itens;

        $this->assertCount(1, $restantes);
        $this->assertSame($ids['MMV-2'], $restantes[0]->id);
        $this->assertNull($cotacao->itens()->whereKey($ids['MMV-1'])->first());
    }
}
