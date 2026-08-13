<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\StatusEngenharia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dependencias da linha de engenharia chegam da tela como lista de numeros ("2,3").
 * O campo vai junto no MESMO request que cria ou edita a linha — nao ha uma segunda
 * chamada para grava-lo. Por isso a regra vale nos dois caminhos:
 *
 *   - campo presente com numeros  -> define exatamente essas dependencias;
 *   - campo presente e vazio      -> limpa as dependencias (unica forma de remover pela tela);
 *   - campo ausente do request    -> nao mexe no que ja esta gravado.
 *
 * O Gantt le essas dependencias para encadear as etapas, entao a gravacao pelo
 * caminho da criacao precisa produzir um cronograma igual ao da edicao.
 */
class DependenciasLinhaEngenhariaTest extends TestCase
{
    use RefreshDatabase;

    private ?User $usuario = null;

    private function usuario(): User
    {
        if ($this->usuario === null) {
            $perfil = Perfil::create([
                'nome' => 'Engenharia Teste',
                'permissoes' => ['engenharia' => 'editar'],
                'ativo' => true,
            ]);

            $this->usuario = User::create([
                'name' => 'Engenheiro',
                'email' => 'eng.dependencias@mmv.test',
                'password' => 'segredo123',
                'perfil_id' => $perfil->id,
                'ativo' => true,
            ]);
        }

        return $this->usuario;
    }

    /** Item de engenharia (header) vindo de um PI, pronto para receber linhas. */
    private function header(): EngenhariaHeader
    {
        StatusEngenharia::firstOrCreate(['nome' => 'A iniciar']);
        StatusEngenharia::firstOrCreate(['nome' => 'Em andamento']);

        $cliente = Cliente::firstOrCreate(['nome' => 'Suzano SA'], ['ativo' => true]);
        $pi = Liberacao::create(['numero_pi' => 'PI-3001', 'cliente_id' => $cliente->id]);
        $itemPi = $pi->itens()->create(['numero_item' => 1, 'descricao' => 'Rolo raspador', 'quantidade' => 1]);
        $demanda = Demanda::create(['tipo' => 'liberacao', 'referencia_id' => $pi->id]);

        return EngenhariaHeader::create([
            'demanda_id' => $demanda->id,
            'cliente_id' => $cliente->id,
            'numero_referencia' => 'PI-3001',
            'nome_item' => 'Rolo raspador',
            // Data fixa (segunda-feira) para o Gantt nao depender do dia em que a suite roda.
            'data_alocacao' => '2025-06-02',
            'liberacao_item_id' => $itemPi->id,
            'status_id' => StatusEngenharia::where('nome', 'A iniciar')->value('id'),
        ]);
    }

    /** POST da tela: cria a linha e devolve o registro persistido. */
    private function adicionarLinha(EngenhariaHeader $header, array $payload): EngenhariaLinha
    {
        $resposta = $this->actingAs($this->usuario())
            ->postJson("/engenharia/{$header->id}/linha", $payload);

        $resposta->assertOk()->assertJson(['ok' => true]);

        return EngenhariaLinha::findOrFail($resposta->json('id'));
    }

    /** PUT da tela sobre uma linha existente. */
    private function editarLinha(EngenhariaHeader $header, EngenhariaLinha $linha, array $payload): void
    {
        $this->actingAs($this->usuario())
            ->putJson("/engenharia/{$header->id}/linha/{$linha->id}", $payload)
            ->assertOk();
    }

    /** @return array<int, array<string, mixed>> tarefas do cronograma do item */
    private function gantt(EngenhariaHeader $header): array
    {
        return $this->actingAs($this->usuario())
            ->getJson("/engenharia/{$header->id}/gantt")
            ->assertOk()
            ->json();
    }

    /** @return array<int, int> numeros de linha das dependencias, ordenados */
    private function numerosDependencias(EngenhariaLinha $linha): array
    {
        return $linha->fresh()->dependencias()
            ->pluck('numero_linha')
            ->map(fn ($n) => (int) $n)
            ->sort()->values()->all();
    }

    // ---- Criacao -----------------------------------------------------------

    /**
     * Regressao: o campo era descartado na criacao e quem digitava "1,2" ao ADICIONAR
     * a linha perdia o valor, precisando salvar e reabrir para informar de novo.
     */
    public function test_criacao_de_linha_ja_grava_as_dependencias_informadas(): void
    {
        $header = $this->header();
        $this->adicionarLinha($header, ['descricao' => 'Corte']);
        $this->adicionarLinha($header, ['descricao' => 'Solda']);

        $terceira = $this->adicionarLinha($header, [
            'descricao' => 'Pintura',
            'dependencias' => '1,2',
        ]);

        $this->assertSame([1, 2], $this->numerosDependencias($terceira));
    }

    public function test_criacao_ignora_numero_de_linha_que_nao_existe_no_item(): void
    {
        $header = $this->header();
        $this->adicionarLinha($header, ['descricao' => 'Corte']);

        $segunda = $this->adicionarLinha($header, [
            'descricao' => 'Solda',
            'dependencias' => '1, 99',
        ]);

        $this->assertSame([1], $this->numerosDependencias($segunda));
    }

    public function test_criacao_sem_a_chave_dependencias_nasce_sem_vinculo(): void
    {
        $header = $this->header();
        $this->adicionarLinha($header, ['descricao' => 'Corte']);

        $segunda = $this->adicionarLinha($header, ['descricao' => 'Solda']);

        $this->assertSame([], $this->numerosDependencias($segunda));
    }

    // ---- Edicao ------------------------------------------------------------

    /**
     * Regressao: com filled() no lugar de has(), mandar o campo vazio nao fazia nada
     * e nao havia como remover uma dependencia pela tela.
     */
    public function test_edicao_com_dependencias_vazio_limpa_as_dependencias(): void
    {
        // Dependencia montada pelo proprio caminho de edicao: isola esta regra da
        // gravacao na criacao, que e a outra metade da correcao.
        $header = $this->header();
        $this->adicionarLinha($header, ['descricao' => 'Corte']);
        $segunda = $this->adicionarLinha($header, ['descricao' => 'Solda']);
        $this->editarLinha($header, $segunda, ['descricao' => 'Solda', 'dependencias' => '1']);
        $this->assertSame([1], $this->numerosDependencias($segunda));

        $this->editarLinha($header, $segunda, ['descricao' => 'Solda', 'dependencias' => '']);

        $this->assertSame([], $this->numerosDependencias($segunda));
    }

    public function test_edicao_sem_a_chave_dependencias_preserva_as_existentes(): void
    {
        $header = $this->header();
        $this->adicionarLinha($header, ['descricao' => 'Corte']);
        $segunda = $this->adicionarLinha($header, ['descricao' => 'Solda']);
        $this->editarLinha($header, $segunda, ['descricao' => 'Solda', 'dependencias' => '1']);

        $this->editarLinha($header, $segunda, ['descricao' => 'Solda TIG']);

        $this->assertSame([1], $this->numerosDependencias($segunda));
        $this->assertSame('Solda TIG', $segunda->fresh()->descricao);
    }

    public function test_edicao_troca_o_conjunto_inteiro_de_dependencias(): void
    {
        $header = $this->header();
        $this->adicionarLinha($header, ['descricao' => 'Corte']);
        $this->adicionarLinha($header, ['descricao' => 'Furacao']);
        $terceira = $this->adicionarLinha($header, ['descricao' => 'Solda', 'dependencias' => '1']);

        $this->editarLinha($header, $terceira, ['descricao' => 'Solda', 'dependencias' => '2']);

        $this->assertSame([2], $this->numerosDependencias($terceira));
    }

    // ---- Auto-dependencia --------------------------------------------------

    public function test_linha_nao_pode_depender_de_si_mesma_na_criacao(): void
    {
        $header = $this->header();
        $this->adicionarLinha($header, ['descricao' => 'Corte']);

        // A segunda linha nasce com numero_linha = 2 e tenta se referenciar.
        $segunda = $this->adicionarLinha($header, [
            'descricao' => 'Solda',
            'dependencias' => '1,2',
        ]);

        $this->assertSame([1], $this->numerosDependencias($segunda));
    }

    public function test_linha_nao_pode_depender_de_si_mesma_na_edicao(): void
    {
        $header = $this->header();
        $primeira = $this->adicionarLinha($header, ['descricao' => 'Corte']);

        $this->editarLinha($header, $primeira, ['descricao' => 'Corte', 'dependencias' => '1']);

        $this->assertSame([], $this->numerosDependencias($primeira));
    }

    // ---- Gantt -------------------------------------------------------------

    /**
     * O cronograma so encadeia as etapas se a dependencia tiver sido gravada. Com o
     * campo descartado na criacao, as tres linhas comecavam no mesmo dia.
     */
    public function test_gantt_encadeia_as_linhas_pelas_dependencias_gravadas_na_criacao(): void
    {
        $header = $this->header();
        $l1 = $this->adicionarLinha($header, ['descricao' => 'Corte', 'duracao_dias' => 2]);
        $l2 = $this->adicionarLinha($header, ['descricao' => 'Solda', 'duracao_dias' => 3, 'dependencias' => '1']);
        $l3 = $this->adicionarLinha($header, ['descricao' => 'Pintura', 'duracao_dias' => 1, 'dependencias' => '2']);

        $tasks = collect($this->gantt($header))->keyBy('id');

        // Base 2025-06-02 (segunda). Cada etapa comeca quando a anterior termina.
        $this->assertSame('2025-06-02', $tasks[(string) $l1->id]['start']);
        $this->assertSame('2025-06-04', $tasks[(string) $l1->id]['end']);
        $this->assertSame('2025-06-04', $tasks[(string) $l2->id]['start']);
        $this->assertSame('2025-06-09', $tasks[(string) $l2->id]['end']);
        $this->assertSame('2025-06-09', $tasks[(string) $l3->id]['start']);
        $this->assertSame('2025-06-10', $tasks[(string) $l3->id]['end']);

        // O frappe-gantt desenha a seta a partir desta lista de ids.
        $this->assertSame((string) $l1->id, $tasks[(string) $l2->id]['dependencies']);
        $this->assertSame((string) $l2->id, $tasks[(string) $l3->id]['dependencies']);
    }

    /** Limpar a dependencia pela tela desencadeia o cronograma de volta. */
    public function test_gantt_volta_a_paralelizar_quando_a_dependencia_e_limpa(): void
    {
        $header = $this->header();
        $l1 = $this->adicionarLinha($header, ['descricao' => 'Corte', 'duracao_dias' => 2]);
        $l2 = $this->adicionarLinha($header, ['descricao' => 'Solda', 'duracao_dias' => 3]);
        $this->editarLinha($header, $l2, ['descricao' => 'Solda', 'duracao_dias' => 3, 'dependencias' => '1']);
        $this->assertSame('2025-06-04', collect($this->gantt($header))->keyBy('id')[(string) $l2->id]['start']);

        $this->editarLinha($header, $l2, ['descricao' => 'Solda', 'duracao_dias' => 3, 'dependencias' => '']);

        $tasks = collect($this->gantt($header))->keyBy('id');

        $this->assertSame('2025-06-02', $tasks[(string) $l1->id]['start']);
        $this->assertSame('2025-06-02', $tasks[(string) $l2->id]['start']);
        $this->assertSame('', $tasks[(string) $l2->id]['dependencies']);
    }

    // ---- Autorizacao -------------------------------------------------------

    public function test_sem_permissao_de_edicao_nao_grava_dependencia_na_criacao(): void
    {
        $header = $this->header();
        $perfil = Perfil::create(['nome' => 'Leitor', 'permissoes' => ['engenharia' => 'ver'], 'ativo' => true]);
        $leitor = User::create([
            'name' => 'Leitor', 'email' => 'leitor.dep@mmv.test', 'password' => 'segredo123',
            'perfil_id' => $perfil->id, 'ativo' => true,
        ]);

        $this->actingAs($leitor)
            ->postJson("/engenharia/{$header->id}/linha", ['descricao' => 'Corte', 'dependencias' => '1'])
            ->assertForbidden();

        $this->assertSame(0, $header->linhas()->count());
    }
}
