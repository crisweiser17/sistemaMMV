<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotacao;
use App\Models\Demanda;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\StatusEngenharia;
use App\Models\UnidadeMedida;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Campos descritivos do item (material_cliente, descricao_cliente, observacoes).
 *
 * Os services de PI e cotacao sempre souberam grava-los, mas os formularios nao
 * renderizavam input nenhum para eles: o navegador nao mandava a chave, o service
 * caia no `?? null` e o valor era zerado a cada salvamento. Na pratica o campo
 * "Observacoes" do cabecalho do item na Engenharia — que le o ITEM, nao o PI —
 * era impossivel de preencher.
 *
 * A grade de itens ganhou uma segunda linha por item para esses campos, o que exigiu
 * trocar o <tbody> unico por um <tbody> POR ITEM, com o <template x-for> como filho
 * direto de <table>. Os testes de estrutura abaixo guardam esse arranjo: se o
 * template sair de dentro da <table>, se aparecer <tbody> aninhado ou se o colspan
 * deixar de fechar a largura do cabecalho, a grade quebra.
 *
 * @see EdicaoItensPedidoTest para a persistencia campo a campo e a preservacao do id.
 */
class CamposDescritivosItemTest extends TestCase
{
    use RefreshDatabase;

    private ?User $usuario = null;

    private function usuario(): User
    {
        if ($this->usuario === null) {
            $perfil = Perfil::create([
                'nome' => 'Comercial Descritivos',
                'permissoes' => [
                    'liberacao' => 'editar',
                    'cotacao' => 'editar',
                    'demandas' => 'editar',
                    'engenharia' => 'editar',
                ],
                'ativo' => true,
            ]);

            $this->usuario = User::create([
                'name' => 'Comercial',
                'email' => 'comercial.descritivos@mmv.test',
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

    private function unidade(): UnidadeMedida
    {
        return UnidadeMedida::firstOrCreate(['sigla' => 'PC'], ['descricao' => 'Peca', 'ativo' => true]);
    }

    /** @return array<string, mixed> */
    private function item(array $sobrescrever = []): array
    {
        return array_merge([
            'numero_item' => 1,
            'cod_mmv' => 'MMV-4711',
            'ni' => 'NI-0099',
            'descricao' => 'Rolo raspador',
            'quantidade' => '2',
            'unidade_id' => $this->unidade()->id,
            'material_cliente' => 'Aco inox 316',
            'descricao_cliente' => 'Rolo conforme desenho do cliente',
            'observacoes' => 'Entregar com laudo dimensional',
        ], $sobrescrever);
    }

    // ---- Estrutura da grade de itens ---------------------------------------

    /**
     * Localiza a tabela de itens do formulario e devolve o que interessa da estrutura.
     *
     * @return array{colunas: int, paiDoTemplate: string, raizes: array<int, string>, tbodysAninhados: int, larguras: array<int, int>, linhas: int}
     */
    private function estruturaDaGrade(string $html): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $template = $xpath->query('//template[contains(@x-for, "in itens")]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $template, 'A grade de itens perdeu o <template x-for>.');

        $raizes = [];
        foreach ($template->childNodes as $filho) {
            if ($filho instanceof DOMElement) {
                $raizes[] = $filho->nodeName;
            }
        }

        $tabela = $template->parentNode;
        $colunas = $xpath->query('thead/tr/th', $tabela)->length;

        $larguras = [];
        foreach ($xpath->query('.//tr', $template) as $tr) {
            $largura = 0;
            foreach ($xpath->query('td', $tr) as $td) {
                $largura += $td instanceof DOMElement ? max(1, (int) $td->getAttribute('colspan')) : 1;
            }
            $larguras[] = $largura;
        }

        return [
            'colunas' => $colunas,
            'paiDoTemplate' => $tabela->nodeName,
            'raizes' => $raizes,
            'tbodysAninhados' => $xpath->query('.//tbody//tbody', $template)->length,
            'larguras' => $larguras,
            'linhas' => $xpath->query('.//tr', $template)->length,
        ];
    }

    /**
     * @param  array<int, string>  $camposEsperados
     */
    private function assertGradeDeItens(string $url, int $colunasEsperadas, array $camposEsperados): void
    {
        $html = $this->actingAs($this->usuario())->get($url)->assertOk()->getContent();

        foreach ($camposEsperados as $campo) {
            $this->assertStringContainsString(
                'itens[${idx}]['.$campo.']',
                $html,
                "{$url}: o formulario nao renderiza input para '{$campo}' — o campo volta a virar null ao salvar."
            );
        }

        $grade = $this->estruturaDaGrade($html);

        $this->assertSame('table', $grade['paiDoTemplate'], "{$url}: o <template x-for> precisa ser filho direto de <table>.");
        $this->assertSame(['tbody'], $grade['raizes'], "{$url}: o x-for do Alpine exige um unico elemento raiz, e ele tem de ser o <tbody>.");
        $this->assertSame(0, $grade['tbodysAninhados'], "{$url}: <tbody> aninhado invalida a tabela.");
        $this->assertSame(2, $grade['linhas'], "{$url}: cada item usa duas linhas (grade principal + campos descritivos).");
        $this->assertSame($colunasEsperadas, $grade['colunas'], "{$url}: o cabecalho mudou de largura.");

        foreach ($grade['larguras'] as $i => $largura) {
            $this->assertSame(
                $colunasEsperadas,
                $largura,
                "{$url}: a linha {$i} do item soma {$largura} colunas e o cabecalho tem {$colunasEsperadas}."
            );
        }
    }

    public function test_formulario_de_pi_novo_tem_os_tres_campos_descritivos_na_grade(): void
    {
        $this->assertGradeDeItens('/liberacao/create', 9, ['material_cliente', 'descricao_cliente', 'observacoes']);
    }

    public function test_formulario_de_pi_em_edicao_tem_os_tres_campos_descritivos_na_grade(): void
    {
        $pi = $this->criarPi([$this->item()]);

        $this->assertGradeDeItens("/liberacao/{$pi->id}/edit", 9, ['material_cliente', 'descricao_cliente', 'observacoes']);
    }

    public function test_formulario_de_cotacao_nova_tem_os_campos_descritivos_na_grade(): void
    {
        // Na cotacao o material do cliente ja tem coluna propria na grade principal.
        $this->assertGradeDeItens('/cotacao/create', 8, ['material_cliente', 'descricao_cliente', 'observacoes']);
    }

    public function test_formulario_de_cotacao_em_edicao_tem_os_campos_descritivos_na_grade(): void
    {
        $cotacao = $this->criarCotacao([$this->item()]);

        $this->assertGradeDeItens("/cotacao/{$cotacao->id}/edit", 8, ['material_cliente', 'descricao_cliente', 'observacoes']);
    }

    /**
     * A outra metade do ida-e-volta: o valor gravado precisa voltar em $itensIniciais,
     * senao o input existe mas abre em branco e o proximo salvamento apaga o campo.
     */
    public function test_edicao_de_pi_devolve_os_valores_gravados_para_o_formulario(): void
    {
        $pi = $this->criarPi([$this->item()]);

        $html = $this->actingAs($this->usuario())->get("/liberacao/{$pi->id}/edit")->assertOk()->getContent();

        foreach (['Aco inox 316', 'Rolo conforme desenho do cliente', 'Entregar com laudo dimensional'] as $valor) {
            $this->assertStringContainsString(e($valor), $html);
        }
    }

    // ---- Persistencia ------------------------------------------------------

    public function test_edicao_que_muda_so_os_campos_descritivos_do_pi_nao_recria_o_item(): void
    {
        $pi = $this->criarPi([$this->item()]);
        $idOriginal = $pi->itens()->value('id');

        $this->actingAs($this->usuario())
            ->put("/liberacao/{$pi->id}", [
                'numero_pi' => 'PI-3301',
                'cliente_id' => $this->cliente()->id,
                'itens' => [$this->item([
                    'id' => $idOriginal,
                    'material_cliente' => 'Aco carbono 1045',
                    'descricao_cliente' => 'Rolo revisado apos reuniao',
                    'observacoes' => 'Pintar com primer epoxi',
                ])],
            ])
            ->assertRedirect();

        $itens = $pi->fresh()->itens;

        $this->assertCount(1, $itens);
        $this->assertSame($idOriginal, $itens[0]->id, 'Mexer so nos campos descritivos nao pode recriar o item.');
        $this->assertSame('Aco carbono 1045', $itens[0]->material_cliente);
        $this->assertSame('Rolo revisado apos reuniao', $itens[0]->descricao_cliente);
        $this->assertSame('Pintar com primer epoxi', $itens[0]->observacoes);
        // Campos da grade principal seguem intactos.
        $this->assertSame('MMV-4711', $itens[0]->cod_mmv);
        $this->assertSame('NI-0099', $itens[0]->ni);
    }

    public function test_edicao_que_muda_so_os_campos_descritivos_da_cotacao_nao_recria_o_item(): void
    {
        $cotacao = $this->criarCotacao([$this->item()]);
        $idOriginal = $cotacao->itens()->value('id');

        $this->actingAs($this->usuario())
            ->put("/cotacao/{$cotacao->id}", [
                'numero' => 'COT-3301',
                'cliente_id' => $this->cliente()->id,
                'itens' => [$this->item([
                    'id' => $idOriginal,
                    'descricao_cliente' => 'Cotar com material do cliente',
                    'observacoes' => 'Prazo critico',
                ])],
            ])
            ->assertRedirect();

        $itens = $cotacao->fresh()->itens;

        $this->assertCount(1, $itens);
        $this->assertSame($idOriginal, $itens[0]->id);
        $this->assertSame('Cotar com material do cliente', $itens[0]->descricao_cliente);
        $this->assertSame('Prazo critico', $itens[0]->observacoes);
        $this->assertSame('Aco inox 316', $itens[0]->material_cliente);
    }

    // ---- Ponta a ponta: do PI ate a tela de Engenharia ----------------------

    /**
     * O motivo de existir dos inputs: o que o comercial digita no item precisa
     * chegar ao cabecalho do item na Engenharia. A tela monta esse cabecalho a
     * partir de EngenhariaHeader::dadosItemOrigem(), serializado no window.ENG.
     */
    public function test_observacao_do_item_do_pi_chega_ao_cabecalho_na_engenharia(): void
    {
        StatusEngenharia::firstOrCreate(['nome' => 'A iniciar']);
        StatusEngenharia::firstOrCreate(['nome' => 'Em andamento']);
        StatusEngenharia::firstOrCreate(['nome' => 'Aguardando']);

        $pi = $this->criarPi([$this->item([
            'material_cliente' => 'Bronze TM23 do cliente',
            'descricao_cliente' => 'Bucha conforme desenho DC-889',
            'observacoes' => 'Nao usinar o rasgo de chaveta',
        ])]);

        $demanda = Demanda::where('tipo', 'liberacao')->where('referencia_id', $pi->id)->firstOrFail();

        $this->actingAs($this->usuario())
            ->putJson("/demandas/{$demanda->id}/alocar", ['responsavel_id' => $this->usuario()->id])
            ->assertOk();

        $html = $this->actingAs($this->usuario())
            ->get("/engenharia/demanda/{$demanda->id}")
            ->assertOk()
            ->getContent();

        // O cabecalho do item e alimentado por window.ENG.items (JSON no HTML).
        foreach (['Bronze TM23 do cliente', 'Bucha conforme desenho DC-889', 'Nao usinar o rasgo de chaveta'] as $valor) {
            $this->assertStringContainsString($valor, $html, "A Engenharia nao mostra '{$valor}' vindo do item do PI.");
        }
    }

    // ---- Apoio -------------------------------------------------------------

    private function criarPi(array $itens): Liberacao
    {
        $this->actingAs($this->usuario())
            ->post('/liberacao', [
                'numero_pi' => 'PI-3301',
                'cliente_id' => $this->cliente()->id,
                'itens' => $itens,
            ])
            ->assertRedirect();

        return Liberacao::latest('id')->firstOrFail();
    }

    private function criarCotacao(array $itens): Cotacao
    {
        $this->actingAs($this->usuario())
            ->post('/cotacao', [
                'numero' => 'COT-3301',
                'cliente_id' => $this->cliente()->id,
                'itens' => $itens,
            ])
            ->assertRedirect();

        return Cotacao::latest('id')->firstOrFail();
    }
}
