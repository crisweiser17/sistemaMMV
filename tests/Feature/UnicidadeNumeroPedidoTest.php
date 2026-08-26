<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotacao;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Numero de PI e numero de cotacao sao unicos GLOBALMENTE: nao por cliente e nao
 * por ano. Dois PIs "1167" no sistema e o defeito que o cliente reportou.
 *
 * Cobre as duas camadas: a validacao que fala com o operador e o indice unico que
 * segura salvamento simultaneo, mais a limpeza das duplicatas ja gravadas.
 */
class UnicidadeNumeroPedidoTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_08_26_000001_adiciona_unicidade_a_numeros_de_pedido.php';

    private ?User $usuario = null;

    /** Mesmo usuario em todas as chamadas do teste (email e unico na tabela). */
    private function usuario(): User
    {
        if ($this->usuario === null) {
            $perfil = Perfil::create([
                'nome' => 'Teste Unicidade',
                'permissoes' => ['liberacao' => 'editar', 'cotacao' => 'editar'],
                'ativo' => true,
            ]);

            $this->usuario = User::create([
                'name' => 'Comercial',
                'email' => 'comercial.unicidade@mmv.test',
                'password' => 'segredo123',
                'perfil_id' => $perfil->id,
                'ativo' => true,
            ]);
        }

        return $this->usuario;
    }

    private function cliente(string $nome): Cliente
    {
        return Cliente::create(['nome' => $nome, 'ativo' => true]);
    }

    /** @return array<string, mixed> */
    private function itemValido(): array
    {
        return ['itens' => [['numero_item' => 1, 'descricao' => 'Eixo principal', 'quantidade' => 1]]];
    }

    // ---- PI: validacao -----------------------------------------------------

    public function test_nao_deixa_criar_pi_com_numero_ja_usado(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());

        $this->post('/liberacao', ['numero_pi' => '1167', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertRedirect();

        $resposta = $this->post('/liberacao', ['numero_pi' => '1167', 'cliente_id' => $cliente->id] + $this->itemValido());

        $resposta->assertSessionHasErrors(['numero_pi' => 'Já existe um PI com este número.']);
        $this->assertSame(1, Liberacao::where('numero_pi', '1167')->count());
    }

    public function test_nao_deixa_repetir_numero_de_pi_entre_clientes_diferentes(): void
    {
        // A definicao de "global": a colisao vale mesmo trocando o cliente.
        $primeiro = $this->cliente('Vale S.A.');
        $segundo = $this->cliente('CSN');
        $this->actingAs($this->usuario());

        $this->post('/liberacao', ['numero_pi' => '1167', 'cliente_id' => $primeiro->id] + $this->itemValido());

        $this->post('/liberacao', ['numero_pi' => '1167', 'cliente_id' => $segundo->id] + $this->itemValido())
            ->assertSessionHasErrors('numero_pi');

        $this->assertSame(1, Liberacao::where('numero_pi', '1167')->count());
    }

    public function test_editar_pi_mantendo_o_proprio_numero_nao_acusa_duplicidade(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());
        $this->post('/liberacao', ['numero_pi' => '1167', 'cliente_id' => $cliente->id] + $this->itemValido());
        $pi = Liberacao::where('numero_pi', '1167')->firstOrFail();

        $resposta = $this->put('/liberacao/'.$pi->id, [
            'numero_pi' => '1167',
            'cliente_id' => $cliente->id,
            'numero_pc' => '4500564452',
        ] + $this->itemValido());

        $resposta->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('4500564452', $pi->fresh()->numero_pc);
    }

    public function test_editar_pi_para_numero_de_outro_pi_e_rejeitado(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());
        $this->post('/liberacao', ['numero_pi' => '1167', 'cliente_id' => $cliente->id] + $this->itemValido());
        $this->post('/liberacao', ['numero_pi' => '1168', 'cliente_id' => $cliente->id] + $this->itemValido());
        $pi = Liberacao::where('numero_pi', '1168')->firstOrFail();

        $this->put('/liberacao/'.$pi->id, ['numero_pi' => '1167', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertSessionHasErrors('numero_pi');

        $this->assertSame('1168', $pi->fresh()->numero_pi);
    }

    public function test_dois_pis_sem_numero_convivem(): void
    {
        // numero_pi e nullable: vazio nao colide com vazio, nem na validacao nem no indice.
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());

        $this->post('/liberacao', ['numero_pi' => '', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertSessionHasNoErrors();
        $this->post('/liberacao', ['numero_pi' => '', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Liberacao::whereNull('numero_pi')->count());
    }

    // ---- Cotacao: validacao ------------------------------------------------

    public function test_nao_deixa_criar_cotacao_com_numero_ja_usado(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());

        $this->post('/cotacao', ['numero' => 'COT-77', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertRedirect();

        $this->post('/cotacao', ['numero' => 'COT-77', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertSessionHasErrors(['numero' => 'Já existe uma cotação com este número.']);

        $this->assertSame(1, Cotacao::where('numero', 'COT-77')->count());
    }

    public function test_nao_deixa_repetir_numero_de_cotacao_entre_clientes_diferentes(): void
    {
        $primeiro = $this->cliente('Vale S.A.');
        $segundo = $this->cliente('CSN');
        $this->actingAs($this->usuario());

        $this->post('/cotacao', ['numero' => 'COT-77', 'cliente_id' => $primeiro->id] + $this->itemValido());

        $this->post('/cotacao', ['numero' => 'COT-77', 'cliente_id' => $segundo->id] + $this->itemValido())
            ->assertSessionHasErrors('numero');

        $this->assertSame(1, Cotacao::where('numero', 'COT-77')->count());
    }

    public function test_editar_cotacao_mantendo_o_proprio_numero_nao_acusa_duplicidade(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());
        $this->post('/cotacao', ['numero' => 'COT-77', 'cliente_id' => $cliente->id] + $this->itemValido());
        $cotacao = Cotacao::where('numero', 'COT-77')->firstOrFail();

        $resposta = $this->put('/cotacao/'.$cotacao->id, [
            'numero' => 'COT-77',
            'cliente_id' => $cliente->id,
            'numero_cliente' => '4500564452',
        ] + $this->itemValido());

        $resposta->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('4500564452', $cotacao->fresh()->numero_cliente);
    }

    public function test_duas_cotacoes_sem_numero_convivem(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());

        $this->post('/cotacao', ['numero' => '', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertSessionHasNoErrors();
        $this->post('/cotacao', ['numero' => '', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Cotacao::whereNull('numero')->count());
    }

    // ---- Feedback na tela --------------------------------------------------

    public function test_formulario_do_pi_mostra_a_mensagem_de_numero_repetido(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());
        $this->post('/liberacao', ['numero_pi' => '1167', 'cliente_id' => $cliente->id] + $this->itemValido());

        $this->from('/liberacao/create')
            ->post('/liberacao', ['numero_pi' => '1167', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertRedirect('/liberacao/create');

        $this->get('/liberacao/create')->assertOk()->assertSee('Já existe um PI com este número.');
    }

    public function test_formulario_da_cotacao_mostra_a_mensagem_de_numero_repetido(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->actingAs($this->usuario());
        $this->post('/cotacao', ['numero' => 'COT-77', 'cliente_id' => $cliente->id] + $this->itemValido());

        $this->from('/cotacao/create')
            ->post('/cotacao', ['numero' => 'COT-77', 'cliente_id' => $cliente->id] + $this->itemValido())
            ->assertRedirect('/cotacao/create');

        $this->get('/cotacao/create')->assertOk()->assertSee('Já existe uma cotação com este número.');
    }

    // ---- Indice no banco ---------------------------------------------------

    public function test_indice_unico_barra_insercao_direta_de_numero_repetido(): void
    {
        // Validacao nao segura dois salvamentos simultaneos; o indice segura.
        DB::table('liberacoes')->insert(['numero_pi' => '1167', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(QueryException::class);

        DB::table('liberacoes')->insert(['numero_pi' => '1167', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- Migration ---------------------------------------------------------

    public function test_migration_limpa_duplicatas_ja_gravadas_e_sobe_o_indice(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $migration = require base_path(self::MIGRATION);

        // Derruba o indice para conseguir plantar o cenario do defeito reportado.
        $migration->down();

        $original = $this->plantarPi(['numero_pi' => '1167', 'cliente_id' => $cliente->id, 'data_pedido' => '2026-01-10']);
        $vazio = $this->plantarPi(['numero_pi' => '1167']);
        $comConteudo = $this->plantarPi(['numero_pi' => '1167', 'cliente_id' => $cliente->id, 'data_pedido' => '2026-02-20']);
        $cotacaoOriginal = $this->plantarCotacao(['numero' => 'COT-77', 'cliente_id' => $cliente->id, 'data_cotacao' => '2026-01-10']);
        $cotacaoDuplicada = $this->plantarCotacao(['numero' => 'COT-77', 'cliente_id' => $cliente->id, 'data_cotacao' => '2026-03-01']);

        $migration->up();

        // O mais antigo fica com o numero original.
        $this->assertSame('1167', Liberacao::withTrashed()->find($original)->numero_pi);
        // Duplicata vazia sai de cena, mas so em soft delete (reversivel).
        $this->assertNull(Liberacao::find($vazio));
        $this->assertNotNull(Liberacao::withTrashed()->find($vazio)->deleted_at);
        // Duplicata com conteudo continua la, renumerada com sufixo previsivel.
        $this->assertSame('1167-2', Liberacao::find($comConteudo)->numero_pi);
        $this->assertSame('COT-77', Cotacao::find($cotacaoOriginal)->numero);
        $this->assertSame('COT-77-2', Cotacao::find($cotacaoDuplicada)->numero);

        // E o indice subiu: repetir de novo agora estoura no banco.
        $this->expectException(QueryException::class);
        DB::table('liberacoes')->insert(['numero_pi' => '1167', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_migration_pode_rodar_de_novo_num_banco_ja_limpo(): void
    {
        $cliente = $this->cliente('Vale S.A.');
        $this->plantarPi(['numero_pi' => '1167', 'cliente_id' => $cliente->id]);
        $migration = require base_path(self::MIGRATION);

        $migration->up();

        $this->assertSame(1, Liberacao::where('numero_pi', '1167')->count());
    }

    /** @param  array<string, mixed>  $atributos */
    private function plantarPi(array $atributos): int
    {
        return DB::table('liberacoes')->insertGetId($atributos + ['created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $atributos */
    private function plantarCotacao(array $atributos): int
    {
        return DB::table('cotacoes')->insertGetId($atributos + ['created_at' => now(), 'updated_at' => now()]);
    }
}
