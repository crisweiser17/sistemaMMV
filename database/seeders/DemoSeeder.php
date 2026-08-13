<?php

namespace Database\Seeders;

use App\Models\CategoriaComponente;
use App\Models\Cliente;
use App\Models\ClienteUnidade;
use App\Models\Demanda;
use App\Models\Escopo;
use App\Models\Liberacao;
use App\Models\Material;
use App\Models\TipoComponente;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Services\CotacaoService;
use App\Services\DemandaService;
use App\Services\EngenhariaService;
use App\Services\LiberacaoService;
use App\Services\OutputService;
use Illuminate\Database\Seeder;

/**
 * Dados de demonstracao que percorrem o fluxo completo usando os proprios Services
 * (cotacao -> liberacao -> demanda -> alocacao -> engenharia -> finalizacao -> PDF).
 * Idempotente: nao recria se ja existir PI de demonstracao.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Liberacao::where('numero_pi', 'like', 'PI-DEMO%')->exists()) {
            $this->command?->warn('Dados de demonstração já existem — nada a fazer.');

            return;
        }

        $cotacaoSvc = app(CotacaoService::class);
        $liberacaoSvc = app(LiberacaoService::class);
        $demandaSvc = app(DemandaService::class);
        $engSvc = app(EngenhariaService::class);
        $outputSvc = app(OutputService::class);

        // Referencias de apoio
        $admin = User::where('email', 'admin@mmv.com')->first();
        $eng = User::where('email', 'engenharia@mmv.com')->first();
        $clientes = Cliente::pluck('id', 'nome');
        $escopos = Escopo::pluck('id', 'descricao');
        $un = fn (string $sigla) => UnidadeMedida::where('sigla', $sigla)->value('id');
        // Unidade (planta) do cliente — ex.: unidadeDe('Suzano SA', 'Tres Lagoas')
        $unidadeDe = fn (string $cliente, string $nome) => ClienteUnidade::query()
            ->where('cliente_id', $clientes[$cliente] ?? 0)
            ->where('nome', $nome)
            ->value('id');

        $catMP = CategoriaComponente::where('tipo', 'materia_prima')->first();
        $tipoMP = TipoComponente::where('categoria_id', $catMP?->id)->first();
        $materialA = Material::where('tipo_id', $tipoMP?->id)->first();
        $materialB = Material::where('tipo_id', $tipoMP?->id)->skip(1)->first() ?? $materialA;

        // ---------------- COTACOES ----------------
        $cotacaoSvc->criar(
            ['numero' => 'COT-2026-101', 'numero_cliente' => 'RFQ-559', 'cliente_id' => $clientes['Vale S.A.'] ?? null,
             'unidade_id' => $unidadeDe('Vale S.A.', 'Carajas'),
             'escopo_id' => $escopos['Recuperacao'] ?? null, 'data_cotacao' => now()->subDays(10)->toDateString(),
             'prazo_resposta' => now()->subDays(3)->toDateString(), 'observacoes' => 'Cotação para recuperação de eixo do britador.'],
            [
                ['numero_item' => 1, 'cod_mmv' => 'MMV-EX-01', 'ni' => 'NI-4501', 'descricao' => 'Eixo do britador primário', 'quantidade' => 1, 'unidade_id' => $un('PC'), 'material_cliente' => 'SAE 4140'],
                ['numero_item' => 2, 'cod_mmv' => 'MMV-BU-02', 'ni' => 'NI-4502', 'descricao' => 'Bucha de bronze', 'quantidade' => 4, 'unidade_id' => $un('PC')],
            ],
            $admin->id
        );

        $cotacaoSvc->criar(
            ['numero' => 'COT-2026-102', 'cliente_id' => $clientes['Gerdau'] ?? null,
             'unidade_id' => $unidadeDe('Gerdau', 'Ouro Branco'), 'escopo_id' => $escopos['Fabricacao'] ?? null,
             'data_cotacao' => now()->subDays(6)->toDateString(), 'observacoes' => 'Fabricação de chute de transferência.'],
            [
                ['numero_item' => 1, 'cod_mmv' => 'MMV-CH-10', 'ni' => 'NI-7720', 'descricao' => 'Chute revestido em chapa', 'quantidade' => 2, 'unidade_id' => $un('PC')],
            ],
            $admin->id
        );

        // ---------------- LIBERACOES (PI) ----------------
        // PI 1 — sera alocado e detalhado (fluxo completo + PDF)
        $pi1 = $liberacaoSvc->criar(
            ['numero_pi' => 'PI-DEMO-1038', 'numero_pc' => 'PC-88231', 'cliente_id' => $clientes['Vale S.A.'] ?? null,
             'unidade_id' => $unidadeDe('Vale S.A.', 'Carajas'),
             'escopo_id' => $escopos['Fabricacao'] ?? null, 'data_pedido' => now()->subDays(5)->toDateString(),
             'nf_cliente' => 'NF-12045', 'observacoes' => 'Prioridade alta — parada de manutenção programada.'],
            [
                // Itens 1 e 3 sem NF propria: herdam a NF-12045 do PI.
                ['numero_item' => 1, 'cod_mmv' => 'MMV-EX-01', 'ni' => 'NI-4501', 'descricao' => 'Eixo principal usinado', 'quantidade' => 1, 'unidade_id' => $un('PC'), 'prazo_entrega_item' => 30],
                // Item 2 chegou em outra remessa: NF propria sobrescreve a do PI.
                ['numero_item' => 2, 'cod_mmv' => 'MMV-MA-05', 'ni' => 'NI-4510', 'descricao' => 'Mancal bipartido', 'quantidade' => 2, 'unidade_id' => $un('PC'), 'nf_cliente' => 'NF-12088', 'prazo_entrega_item' => 45],
                ['numero_item' => 3, 'cod_mmv' => 'MMV-TP-09', 'descricao' => 'Tampa lateral', 'quantidade' => 2, 'unidade_id' => $un('PC'), 'prazo_entrega_item' => 20],
            ],
            $admin->id
        );

        // PI 2 — alocado, em andamento (sem finalizar)
        $pi2 = $liberacaoSvc->criar(
            ['numero_pi' => 'PI-DEMO-1039', 'numero_pc' => 'PC-88240', 'cliente_id' => $clientes['CSN'] ?? null,
             'unidade_id' => $unidadeDe('CSN', 'Volta Redonda'),
             'escopo_id' => $escopos['Recuperacao'] ?? null, 'data_pedido' => now()->subDays(3)->toDateString(),
             'nf_cliente' => 'NF-30877', 'observacoes' => 'Recuperação de rolo de mesa.'],
            [
                ['numero_item' => 1, 'cod_mmv' => 'MMV-RL-21', 'ni' => 'NI-9001', 'descricao' => 'Rolo de mesa recuperado', 'quantidade' => 6, 'unidade_id' => $un('PC'), 'prazo_entrega_item' => 25],
                ['numero_item' => 2, 'cod_mmv' => 'MMV-EX-22', 'descricao' => 'Eixo do rolo', 'quantidade' => 6, 'unidade_id' => $un('PC'), 'nf_cliente' => 'NF-30912', 'prazo_entrega_item' => 25],
            ],
            $admin->id
        );

        // PI 3 — recem-criado, ainda aguardando (nao alocado).
        // Cliente com mais de uma unidade: mostra o rotulo "Suzano SA – Ribas do Rio Pardo".
        $liberacaoSvc->criar(
            ['numero_pi' => 'PI-DEMO-1040', 'cliente_id' => $clientes['Suzano SA'] ?? null,
             'unidade_id' => $unidadeDe('Suzano SA', 'Ribas do Rio Pardo'), 'escopo_id' => $escopos['Fabricacao'] ?? null,
             'data_pedido' => now()->subDay()->toDateString(), 'observacoes' => 'Aguardando alocação na engenharia.'],
            [
                // PI sem NF no cabecalho: a NF chega so no item.
                ['numero_item' => 1, 'cod_mmv' => 'MMV-GR-30', 'descricao' => 'Grelha fundida', 'quantidade' => 10, 'unidade_id' => $un('PC'), 'nf_cliente' => 'NF-77120', 'prazo_entrega_item' => 15],
            ],
            $admin->id
        );

        // ---------------- ALOCACAO + ENGENHARIA ----------------
        $demandaPi1 = Demanda::where('tipo', 'liberacao')->where('referencia_id', $pi1->id)->first();
        $demandaPi2 = Demanda::where('tipo', 'liberacao')->where('referencia_id', $pi2->id)->first();

        $demandaSvc->alocar($demandaPi1, $eng->id);   // gera 3 headers
        $demandaSvc->alocar($demandaPi2, $eng->id);   // gera 2 headers

        // Detalha os headers do PI-1038
        $headersPi1 = $demandaPi1->fresh()->headers()->orderBy('id')->get();

        // Header 1 (Eixo): linhas de materia-prima + servico + comercial, com dependencia
        $h1 = $headersPi1[0];
        $l1 = $engSvc->adicionarLinha($h1, ['cod_mmv' => 'MMV-EX-01', 'descricao' => 'Tarugo SAE 4140 Ø150',
            'tipo_componente' => 'materia_prima', 'categoria_componente_id' => $catMP?->id, 'tipo_componente_id' => $tipoMP?->id,
            'material_id' => $materialA?->id, 'quantidade' => 1, 'unidade_id' => $un('PC'), 'fase' => 'Suprimento']);
        $l2 = $engSvc->adicionarLinha($h1, ['descricao' => 'Torneamento de desbaste e acabamento',
            'tipo_componente' => 'servico', 'mao_de_obra' => 'Torno CNC — 8h', 'quantidade' => 1, 'fase' => 'Usinagem']);
        $engSvc->adicionarLinha($h1, ['descricao' => 'Chaveta DIN 6885', 'tipo_componente' => 'comercial',
            'quantidade' => 2, 'unidade_id' => $un('PC'), 'fase' => 'Montagem']);
        // Usinagem (linha 2) depende do suprimento (linha 1)
        $engSvc->definirDependenciasPorNumeros($l2, [$l1->numero_linha]);

        // Header 2 (Mancal): algumas linhas e finalizado
        $h2 = $headersPi1[1];
        $engSvc->adicionarLinha($h2, ['descricao' => 'Chapa ASTM A36 1"', 'tipo_componente' => 'materia_prima',
            'categoria_componente_id' => $catMP?->id, 'tipo_componente_id' => $tipoMP?->id, 'material_id' => $materialB?->id,
            'quantidade' => 2, 'unidade_id' => $un('PC'), 'fase' => 'Suprimento']);
        $engSvc->adicionarLinha($h2, ['descricao' => 'Solda e alívio de tensões', 'tipo_componente' => 'servico',
            'mao_de_obra' => 'Soldador + forno', 'quantidade' => 1, 'fase' => 'Fabricação']);
        $engSvc->finalizar($h2);   // item finalizado

        // Header 3 (Tampa): finalizado tambem
        $h3 = $headersPi1[2];
        $engSvc->adicionarLinha($h3, ['descricao' => 'Corte a laser de tampa', 'tipo_componente' => 'servico',
            'mao_de_obra' => 'Laser', 'quantidade' => 2, 'fase' => 'Corte']);
        $engSvc->finalizar($h3);

        // Gera um PDF de Output para a demanda do PI1 (o output e por DEMANDA, agrupando os itens).
        $outputSvc->gerarPdf($demandaPi1->fresh(), $eng->id);

        $this->command?->info('Demonstração criada: 2 cotações, 3 PIs, demandas, engenharia detalhada e 1 PDF.');
    }
}
