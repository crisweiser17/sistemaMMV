<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Services\ClienteUnidadeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cadastro de Cliente com as unidades (plantas) dentro do proprio formulario:
 * um cliente para uma ou mais unidades, tudo num submit so.
 *
 * Fica fora do CRUD generico do ResourceRegistry de proposito — e o unico
 * recurso com filhos editaveis, e um repeater nao caberia la sem distorcer o
 * comportamento dos outros dez cadastros de apoio.
 */
class ClienteController extends Controller
{
    public function __construct(private readonly ClienteUnidadeService $unidades) {}

    public function index(): View
    {
        $clientes = Cliente::query()->with('unidades')->orderBy('nome')->paginate(15);

        return view('admin.clientes.index', compact('clientes'));
    }

    public function create(): View
    {
        return view('admin.clientes.form', ['cliente' => new Cliente]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->dadosValidados($request, null);

        DB::transaction(function () use ($dados) {
            $cliente = Cliente::create($dados['cliente']);
            $this->unidades->sincronizar($cliente, $dados['unidades']);
        });

        return redirect()->route('admin.clientes.index')->with('success', 'Cliente criado com sucesso.');
    }

    public function edit(int $id): View
    {
        $cliente = Cliente::with('unidades')->findOrFail($id);

        return view('admin.clientes.form', compact('cliente'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $cliente = Cliente::findOrFail($id);
        $dados = $this->dadosValidados($request, $cliente);

        // Transacao: se a sincronizacao barrar uma remocao perigosa, nem os dados
        // do cliente mudam — ou o submit inteiro passa, ou nada muda.
        DB::transaction(function () use ($cliente, $dados) {
            $cliente->update($dados['cliente']);
            $this->unidades->sincronizar($cliente, $dados['unidades']);
        });

        return redirect()->route('admin.clientes.index')->with('success', 'Cliente atualizado com sucesso.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Cliente::findOrFail($id)->delete();

        return redirect()->route('admin.clientes.index')->with('success', 'Cliente removido.');
    }

    /**
     * Valida cliente + linhas do repeater e devolve so o que vai ser gravado.
     *
     * @return array{cliente: array<string, mixed>, unidades: array<int, array<string, mixed>>}
     */
    private function dadosValidados(Request $request, ?Cliente $cliente): array
    {
        // A fabrica do repeater sempre deixa uma linha em branco na tela; linha
        // que o usuario nao preencheu nao e unidade e nao vai para a validacao.
        $request->merge(['unidades' => $this->semLinhasVazias($request->input('unidades', []))]);

        $regras = array_merge([
            'nome' => ['required', 'string', 'max:255'],
            'ativo' => ['boolean'],
        ], ClienteUnidadeService::regrasDoRepeater($cliente?->id));

        $validados = $request->validate($regras);

        return [
            'cliente' => ['nome' => $validados['nome'], 'ativo' => $request->boolean('ativo')],
            'unidades' => array_values($validados['unidades'] ?? []),
        ];
    }

    /**
     * @param  array<int, mixed>  $linhas
     * @return array<int, array<string, mixed>>
     */
    private function semLinhasVazias(array $linhas): array
    {
        return collect($linhas)
            ->filter(fn ($linha) => is_array($linha))
            ->reject(fn (array $linha) => blank($linha['id'] ?? null)
                && blank($linha['nome'] ?? null)
                && blank($linha['codigo'] ?? null))
            ->values()
            ->all();
    }
}
