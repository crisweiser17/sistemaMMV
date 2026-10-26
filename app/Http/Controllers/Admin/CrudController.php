<?php

namespace App\Http\Controllers\Admin;

use App\Admin\ResourceRegistry;
use App\Http\Controllers\Controller;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD generico para todos os cadastros de apoio, dirigido pelo ResourceRegistry.
 * O slug do recurso vem de route defaults('resource', ...).
 */
class CrudController extends Controller
{
    private function config(Request $request): array
    {
        $slug = $request->route('resource');
        $config = ResourceRegistry::get($slug);
        abort_if(! $config, Response::HTTP_NOT_FOUND);
        $config['slug'] = $slug;

        return $config;
    }

    /** Recursos marcados 'apenasAdministrador' so podem ser alterados pelo perfil Administrador. */
    private function podeAlterar(Request $request, array $config): bool
    {
        return ! ($config['apenasAdministrador'] ?? false) || (bool) $request->user()?->ehAdministrador();
    }

    private function exigirPodeAlterar(Request $request, array $config): void
    {
        abort_unless($this->podeAlterar($request, $config), Response::HTTP_FORBIDDEN,
            "Apenas o Administrador pode alterar {$config['label']}.");
    }

    /** Resolve opcoes de selects declaradas via optionsFrom => [ModelClass, labelColumn]. */
    private function selectOptions(array $field): array
    {
        if (isset($field['options'])) {
            return $field['options'];
        }
        if (isset($field['optionsFrom'])) {
            [$modelClass, $labelCol] = $field['optionsFrom'];
            return $modelClass::query()->orderBy($labelCol)->pluck($labelCol, 'id')->all();
        }

        return [];
    }

    private function withOptions(array $config): array
    {
        foreach ($config['fields'] as &$field) {
            if ($field['type'] === 'select') {
                $field['_options'] = $this->selectOptions($field);
            } elseif ($field['type'] === 'usuarios') {
                $field['_usuarios'] = User::query()->with('perfil')->orderBy('name')->get();
            }
        }

        return $config;
    }

    public function index(Request $request): View
    {
        $config = $this->withOptions($this->config($request));
        /** @var class-string<Model> $model */
        $model = $config['model'];

        $with = collect($config['fields'])
            ->filter(fn ($f) => isset($f['display']) && str_contains($f['display'], '.'))
            ->map(fn ($f) => Str::before($f['display'], '.'))
            ->unique()->values()->all();
        if (collect($config['fields'])->contains('type', 'usuarios')) {
            $with[] = 'users';
        }

        $registros = $model::query()->with($with)->latest()->paginate(15);
        $podeAlterar = $this->podeAlterar($request, $config);

        return view('admin.crud.index', compact('config', 'registros', 'podeAlterar'));
    }

    public function create(Request $request): View
    {
        $config = $this->withOptions($this->config($request));
        $this->exigirPodeAlterar($request, $config);
        $registro = null;

        return view('admin.crud.form', compact('config', 'registro'));
    }

    public function store(Request $request): RedirectResponse
    {
        $config = $this->config($request);
        $this->exigirPodeAlterar($request, $config);
        $model = $config['model'];

        $data = $this->validateData($request, $config);
        [$data, $usuarios] = $this->separarUsuarios($config, $data);
        if ($erro = $this->erroAoAtribuirUsuarios($request, null, $usuarios)) {
            return back()->withInput()->with('error', $erro);
        }
        $registro = $model::create($data);
        $this->sincronizarUsuarios($registro, $usuarios);

        return redirect()->route("admin.{$config['slug']}.index")
            ->with('success', "{$config['singular']} criado com sucesso.");
    }

    public function edit(Request $request, int $id): View
    {
        $config = $this->withOptions($this->config($request));
        $this->exigirPodeAlterar($request, $config);
        $registro = $config['model']::findOrFail($id);

        return view('admin.crud.form', compact('config', 'registro'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $config = $this->config($request);
        $this->exigirPodeAlterar($request, $config);
        $registro = $config['model']::findOrFail($id);

        $data = $this->validateData($request, $config, $registro->getKey());
        [$data, $usuarios] = $this->separarUsuarios($config, $data);
        if ($erro = $this->erroAoAtribuirUsuarios($request, $registro, $usuarios)) {
            return back()->withInput()->with('error', $erro);
        }
        // O acesso de administrador e reconhecido pelo nome do perfil: renomear o
        // Administrador tiraria de todo mundo a chance de voltar a editar perfis.
        if ($registro instanceof Perfil && $registro->ehAdministrador()) {
            $data['nome'] = Perfil::ADMINISTRADOR;
        }
        // Quem edita o proprio cadastro nao pode se desativar nem sair do Administrador:
        // ficaria trancado fora da tela que desfaz isso.
        if ($registro instanceof User && $registro->is($request->user())) {
            if (! $data['ativo']) {
                return back()->withInput()->with('error', 'Você não pode desativar o seu próprio usuário.');
            }
            if ($registro->ehAdministrador() && Perfil::find($data['perfil_id'])?->ehAdministrador() !== true) {
                return back()->withInput()->with('error', 'Você não pode tirar o seu próprio perfil de Administrador.');
            }
        }
        $registro->update($data);
        $this->sincronizarUsuarios($registro, $usuarios);

        return redirect()->route("admin.{$config['slug']}.index")
            ->with('success', "{$config['singular']} atualizado com sucesso.");
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $config = $this->config($request);
        $this->exigirPodeAlterar($request, $config);
        $registro = $config['model']::findOrFail($id);

        if ($registro instanceof Perfil && $registro->ehAdministrador()) {
            return back()->with('error', 'O perfil Administrador não pode ser removido.');
        }
        if ($registro instanceof User && $registro->is($request->user())) {
            return back()->with('error', 'Você não pode excluir o seu próprio usuário.');
        }
        $registro->delete();

        return redirect()->route("admin.{$config['slug']}.index")
            ->with('success', "{$config['singular']} removido.");
    }

    /**
     * Tira do $data o campo 'usuarios' (nao e coluna). Devolve null quando o formulario
     * nao enviou a lista, para nao mexer nos usuarios do perfil.
     *
     * @return array{0: array, 1: array<int, int>|null}
     */
    private function separarUsuarios(array $config, array $data): array
    {
        $campo = collect($config['fields'])->firstWhere('type', 'usuarios');
        if (! $campo) {
            return [$data, null];
        }
        $usuarios = $data[$campo['name']] ?? null;
        unset($data[$campo['name']]);

        return [$data, $usuarios];
    }

    /** Impede que o Administrador logado se tire do proprio perfil pela tela de perfis. */
    private function erroAoAtribuirUsuarios(Request $request, ?Model $perfil, ?array $usuarios): ?string
    {
        $eu = $request->user();
        if ($usuarios === null || ! $eu?->ehAdministrador()) {
            return null;
        }
        $marcado = in_array($eu->getKey(), $usuarios, true);
        $perfilAdmin = $perfil instanceof Perfil && $perfil->ehAdministrador();

        if ($perfilAdmin && ! $marcado) {
            return 'Você não pode tirar o seu próprio usuário do perfil Administrador.';
        }
        if (! $perfilAdmin && $marcado) {
            return 'Você não pode trocar o seu próprio perfil de Administrador.';
        }

        return null;
    }

    /**
     * Marcados passam para este perfil (saindo do anterior, ja que cada usuario tem um so);
     * desmarcados que estavam nele ficam sem perfil, ou seja, sem acesso.
     */
    private function sincronizarUsuarios(Model $perfil, ?array $usuarios): void
    {
        if ($usuarios === null) {
            return;
        }
        User::query()->whereIn('id', $usuarios)->update(['perfil_id' => $perfil->getKey()]);
        User::query()->where('perfil_id', $perfil->getKey())->whereNotIn('id', $usuarios)->update(['perfil_id' => null]);
    }

    /** $id e o registro em edicao (null na criacao): substitui {id} nas regras, ex. unique. */
    private function validateData(Request $request, array $config, ?int $id = null): array
    {
        $rules = [];
        foreach ($config['fields'] as $field) {
            $rules[$field['name']] = str_replace('{id}', $id ?? 'NULL', $field['rules'] ?? 'nullable');
            if ($field['type'] === 'password' && $id === null) {
                $rules[$field['name']] = 'required|'.$rules[$field['name']];
            }
            if ($field['type'] === 'permissoes') {
                $rules[$field['name'].'.*'] = 'in:'.implode(',', array_keys(Perfil::NIVEIS));
            }
            if ($field['type'] === 'usuarios') {
                $rules[$field['name'].'.*'] = 'nullable|integer|exists:users,id';
            }
        }
        $data = $request->validate($rules);

        // Normalizacoes por tipo
        foreach ($config['fields'] as $field) {
            $name = $field['name'];
            if ($field['type'] === 'password') {
                // Em branco na edicao = manter a atual. O cast 'hashed' do model criptografa.
                if (blank($data[$name] ?? null)) {
                    unset($data[$name]);
                }
            } elseif ($field['type'] === 'boolean') {
                $data[$name] = $request->boolean($name);
            } elseif ($field['type'] === 'json') {
                $raw = $request->input($name);
                $data[$name] = is_string($raw) ? (json_decode($raw, true) ?: []) : ($raw ?: []);
            } elseif ($field['type'] === 'usuarios') {
                // O form manda um usuarios[] vazio para "nenhum marcado"; sem a chave, nao mexe.
                $data[$name] = $request->has($name)
                    ? collect((array) $request->input($name))->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()
                    : null;
            } elseif ($field['type'] === 'permissoes') {
                // Grava so os modulos conhecidos com acesso; 'nenhum' fica de fora do JSON.
                $niveis = (array) $request->input($name, []);
                $data[$name] = collect(Perfil::MODULOS)
                    ->map(fn ($m, $modulo) => $niveis[$modulo] ?? 'nenhum')
                    ->reject(fn ($nivel) => $nivel === 'nenhum')
                    ->all();
            }
        }

        return $data;
    }
}
