<?php

namespace App\Http\Controllers;

use App\Models\Cotacao;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\Liberacao;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $cards = [
            ['label' => 'Cotações', 'valor' => Cotacao::count(), 'rota' => 'cotacao.index', 'cor' => '#EF8332'],
            ['label' => 'Liberações (PI)', 'valor' => Liberacao::count(), 'rota' => 'liberacao.index', 'cor' => '#1E1E1E'],
            ['label' => 'Demandas abertas', 'valor' => Demanda::whereNull('data_entrega_engenharia')->count(), 'rota' => 'demandas.index', 'cor' => '#f59e0b'],
            ['label' => 'Itens em engenharia', 'valor' => EngenhariaHeader::count(), 'rota' => 'engenharia.index', 'cor' => '#10b981'],
        ];

        return view('dashboard', compact('cards'));
    }
}
