<?php

namespace App\Http\Controllers\Admin;

use App\Admin\ResourceRegistry;
use App\Http\Controllers\Controller;
use App\Models\Perfil;
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
        $model::create($data);

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

        $data = $this->validateData($request, $config);
        // O acesso de administrador e reconhecido pelo nome do perfil: renomear o
        // Administrador tiraria de todo mundo a chance de voltar a editar perfis.
        if ($registro instanceof Perfil && $registro->ehAdministrador()) {
            $data['nome'] = Perfil::ADMINISTRADOR;
        }
        $registro->update($data);

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
        $registro->delete();

        return redirect()->route("admin.{$config['slug']}.index")
            ->with('success', "{$config['singular']} removido.");
    }

    private function validateData(Request $request, array $config): array
    {
        $rules = [];
        foreach ($config['fields'] as $field) {
            $rules[$field['name']] = $field['rules'] ?? 'nullable';
            if ($field['type'] === 'permissoes') {
                $rules[$field['name'].'.*'] = 'in:'.implode(',', array_keys(Perfil::NIVEIS));
            }
        }
        $data = $request->validate($rules);

        // Normalizacoes por tipo
        foreach ($config['fields'] as $field) {
            $name = $field['name'];
            if ($field['type'] === 'boolean') {
                $data[$name] = $request->boolean($name);
            } elseif ($field['type'] === 'json') {
                $raw = $request->input($name);
                $data[$name] = is_string($raw) ? (json_decode($raw, true) ?: []) : ($raw ?: []);
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
