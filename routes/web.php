<?php

use App\Admin\ResourceRegistry;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ClienteController;
use App\Http\Controllers\Admin\CrudController;
use App\Http\Controllers\Admin\LookupController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CotacaoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemandaController;
use App\Http\Controllers\EngenhariaController;
use App\Http\Controllers\LiberacaoController;
use App\Http\Controllers\OutputController;
use Illuminate\Support\Facades\Route;

// ---- Autenticacao ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---- App (autenticado + usuario ativo) ----
Route::middleware(['auth', 'ativo'])->group(function () {
    Route::get('/', fn () => redirect()->route('demandas.index'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::view('/manual', 'manual.index')->name('manual');

    // Cotacao
    Route::prefix('cotacao')->name('cotacao.')->group(function () {
        Route::get('/', [CotacaoController::class, 'index'])->name('index');
        Route::get('/busca-ni', [CotacaoController::class, 'buscaNi'])->name('busca-ni');
        Route::get('/create', [CotacaoController::class, 'create'])->name('create');
        Route::post('/', [CotacaoController::class, 'store'])->name('store');
        Route::get('/{cotacao}', [CotacaoController::class, 'show'])->name('show');
        Route::get('/{cotacao}/edit', [CotacaoController::class, 'edit'])->name('edit');
        Route::put('/{cotacao}', [CotacaoController::class, 'update'])->name('update');
        Route::post('/{cotacao}/anexo', [CotacaoController::class, 'anexo'])->name('anexo');
        Route::get('/{cotacao}/anexo/{anexo}', [CotacaoController::class, 'verAnexo'])->name('anexo.ver');
        Route::delete('/{cotacao}/anexo/{anexo}', [CotacaoController::class, 'removeAnexo'])->name('anexo.remove');
    });

    // Liberacao (PI)
    Route::prefix('liberacao')->name('liberacao.')->group(function () {
        Route::get('/', [LiberacaoController::class, 'index'])->name('index');
        Route::get('/create', [LiberacaoController::class, 'create'])->name('create');
        Route::post('/', [LiberacaoController::class, 'store'])->name('store');
        Route::get('/{liberacao}', [LiberacaoController::class, 'show'])->name('show');
        Route::get('/{liberacao}/edit', [LiberacaoController::class, 'edit'])->name('edit');
        Route::put('/{liberacao}', [LiberacaoController::class, 'update'])->name('update');
        Route::post('/{liberacao}/item/{item}/anexo', [LiberacaoController::class, 'anexoItem'])->name('item.anexo');
        Route::get('/{liberacao}/item-anexo/{anexo}', [LiberacaoController::class, 'verAnexoItem'])->name('item.anexo.ver');
        Route::delete('/{liberacao}/item-anexo/{anexo}', [LiberacaoController::class, 'removeAnexoItem'])->name('item.anexo.remove');
        Route::post('/{liberacao}/anexo', [LiberacaoController::class, 'anexo'])->name('anexo');
        Route::get('/{liberacao}/anexo/{anexo}', [LiberacaoController::class, 'verAnexo'])->name('anexo.ver');
        Route::delete('/{liberacao}/anexo/{anexo}', [LiberacaoController::class, 'removeAnexo'])->name('anexo.remove');
    });

    // Controle de Demandas (com endpoint JSON reativo)
    Route::prefix('demandas')->name('demandas.')->group(function () {
        Route::get('/', [DemandaController::class, 'index'])->name('index');
        Route::get('/data', [DemandaController::class, 'data'])->name('data');
        Route::put('/{demanda}/alocar', [DemandaController::class, 'alocar'])->name('alocar');
        Route::put('/{demanda}/status', [DemandaController::class, 'status'])->name('status');
    });

    // Engenharia
    Route::prefix('engenharia')->name('engenharia.')->group(function () {
        Route::get('/', [EngenhariaController::class, 'index'])->name('index');
        Route::get('/data', [EngenhariaController::class, 'data'])->name('data');
        Route::get('/demanda/{demanda}', [EngenhariaController::class, 'demanda'])->name('demanda');
        Route::get('/{header}/linhas', [EngenhariaController::class, 'linhas'])->name('linhas');
        Route::get('/{header}/gantt', [EngenhariaController::class, 'gantt'])->name('gantt');
        Route::post('/{header}/linha', [EngenhariaController::class, 'addLinha'])->name('linha.add');
        Route::put('/{header}/linha/{linha}', [EngenhariaController::class, 'updLinha'])->name('linha.upd');
        Route::delete('/{header}/linha/{linha}', [EngenhariaController::class, 'delLinha'])->name('linha.del');
        Route::post('/{header}/linha/{linha}/arquivo', [EngenhariaController::class, 'upload'])->name('linha.upload');
        Route::get('/{header}/linha/{linha}/arquivo', [EngenhariaController::class, 'verArquivo'])->name('linha.arquivo.ver');
        Route::delete('/{header}/linha/{linha}/arquivo', [EngenhariaController::class, 'removerArquivo'])->name('linha.arquivo.remove');
        Route::post('/{header}/linha/{linha}/dependencia', [EngenhariaController::class, 'addDep'])->name('dependencia');
        // Copia da estrutura de um item ja concluido para este item
        Route::get('/{header}/estruturas', [EngenhariaController::class, 'estruturas'])->name('estruturas');
        Route::post('/{header}/copiar-estrutura', [EngenhariaController::class, 'copiarEstrutura'])->name('estrutura.copiar');
        Route::put('/{header}/finalizar', [EngenhariaController::class, 'finalizar'])->name('finalizar');
    });

    // Output PDF (documento PI por demanda — agrupa todos os itens)
    Route::prefix('output')->name('output.')->group(function () {
        Route::get('/{demanda}', [OutputController::class, 'preview'])->name('preview');
        Route::post('/{demanda}/gerar', [OutputController::class, 'gerar'])->name('gerar');
        Route::get('/{demanda}/download', [OutputController::class, 'download'])->name('download');
        Route::get('/{demanda}/historico', [OutputController::class, 'historico'])->name('historico');
        // Historico das mudancas feitas depois do ultimo PDF (tela propria: a lista
        // e cronologica e por campo, nao cabe junto da lista de versoes do PDF).
        Route::get('/{demanda}/alteracoes', [OutputController::class, 'alteracoes'])->name('alteracoes');
    });

    // Admin: hub + lookups JSON + CRUD generico por recurso
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');

        // Lookups JSON (dropdowns encadeados)
        Route::get('/categorias', [LookupController::class, 'categorias'])->name('categorias');
        Route::get('/tipos', [LookupController::class, 'tipos'])->name('tipos');
        Route::get('/processos', [LookupController::class, 'processos'])->name('processos');
        // Prefixo /lookup para nao colidir com a rota de CRUD do recurso homonimo.
        // 'materiais' e 'unidades' sao slugs do ResourceRegistry: sem o prefixo, o CRUD
        // (registrado depois) sobrescreve o lookup e o dropdown recebe HTML no lugar do JSON.
        Route::get('/lookup/materiais', [LookupController::class, 'materiais'])->name('lookup.materiais');
        Route::get('/lookup/unidades', [LookupController::class, 'unidadesDoCliente'])->name('lookup.unidades');

        // Cliente tem tela propria: as unidades (plantas) sao editadas dentro do
        // cadastro do cliente, num submit so — coisa que o CRUD generico nao faz.
        Route::prefix('clientes')->name('clientes.')->group(function () {
            Route::get('/', [ClienteController::class, 'index'])->name('index');
            Route::get('/create', [ClienteController::class, 'create'])->name('create');
            Route::post('/', [ClienteController::class, 'store'])->name('store');
            Route::get('/{id}/edit', [ClienteController::class, 'edit'])->name('edit');
            Route::put('/{id}', [ClienteController::class, 'update'])->name('update');
            Route::delete('/{id}', [ClienteController::class, 'destroy'])->name('destroy');
        });

        // CRUD generico — um conjunto de rotas nomeadas por recurso do registry
        foreach (ResourceRegistry::crudSlugs() as $slug) {
            Route::prefix($slug)->name("{$slug}.")->group(function () use ($slug) {
                Route::get('/', [CrudController::class, 'index'])->defaults('resource', $slug)->name('index');
                Route::get('/create', [CrudController::class, 'create'])->defaults('resource', $slug)->name('create');
                Route::post('/', [CrudController::class, 'store'])->defaults('resource', $slug)->name('store');
                Route::get('/{id}/edit', [CrudController::class, 'edit'])->defaults('resource', $slug)->name('edit');
                Route::put('/{id}', [CrudController::class, 'update'])->defaults('resource', $slug)->name('update');
                Route::delete('/{id}', [CrudController::class, 'destroy'])->defaults('resource', $slug)->name('destroy');
            });
        }
    });
});
