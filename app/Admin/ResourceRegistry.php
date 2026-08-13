<?php

namespace App\Admin;

use App\Models\CategoriaComponente;
use App\Models\Cliente;
use App\Models\ClienteUnidade;
use App\Models\ComponenteComercial;
use App\Models\Escopo;
use App\Models\Material;
use App\Models\Perfil;
use App\Models\ProcessoFabricacao;
use App\Models\StatusEngenharia;
use App\Models\TipoComponente;
use App\Models\UnidadeMedida;

/**
 * Registro declarativo dos cadastros de apoio.
 * Um unico CrudController + views genericas consomem esta definicao,
 * evitando 9+ controllers/views quase identicos.
 */
class ResourceRegistry
{
    /** @return array<string, array> */
    public static function all(): array
    {
        return [
            'clientes' => [
                'label' => 'Clientes', 'singular' => 'Cliente', 'model' => Cliente::class,
                'fields' => [
                    ['name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'rules' => 'required|string|max:255', 'list' => true],
                    ['name' => 'codigo_pa', 'label' => 'Código PA', 'type' => 'text', 'rules' => 'nullable|string|max:50', 'list' => true],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            // Unidades (plantas) do cliente — substitui a antiga coluna clientes.unidade.
            'unidades-cliente' => [
                'label' => 'Unidades de Cliente', 'singular' => 'Unidade', 'model' => ClienteUnidade::class,
                'fields' => [
                    ['name' => 'cliente_id', 'label' => 'Cliente', 'type' => 'select', 'rules' => 'required|exists:clientes,id', 'list' => true,
                        'optionsFrom' => [Cliente::class, 'nome'], 'display' => 'cliente.nome'],
                    ['name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'rules' => 'required|string|max:255', 'list' => true],
                    ['name' => 'codigo', 'label' => 'Código', 'type' => 'text', 'rules' => 'nullable|string|max:50', 'list' => true],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'escopos' => [
                'label' => 'Escopos', 'singular' => 'Escopo', 'model' => Escopo::class,
                'fields' => [
                    ['name' => 'codigo', 'label' => 'Código', 'type' => 'text', 'rules' => 'nullable|string|max:50', 'list' => true],
                    ['name' => 'descricao', 'label' => 'Descrição', 'type' => 'text', 'rules' => 'required|string|max:255', 'list' => true],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'unidades' => [
                'label' => 'Unidades de Medida', 'singular' => 'Unidade', 'model' => UnidadeMedida::class,
                'fields' => [
                    ['name' => 'sigla', 'label' => 'Sigla', 'type' => 'text', 'rules' => 'required|string|max:20', 'list' => true],
                    ['name' => 'descricao', 'label' => 'Descrição', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'categorias-componente' => [
                'label' => 'Categorias de Componente', 'singular' => 'Categoria', 'model' => CategoriaComponente::class,
                'fields' => [
                    ['name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'rules' => 'required|string|max:255', 'list' => true],
                    ['name' => 'tipo', 'label' => 'Tipo', 'type' => 'select', 'rules' => 'required|in:materia_prima,servico,comercial', 'list' => true,
                        'options' => ['materia_prima' => 'Matéria-prima', 'servico' => 'Serviço', 'comercial' => 'Comercial']],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'tipos-componente' => [
                'label' => 'Tipos de Componente', 'singular' => 'Tipo', 'model' => TipoComponente::class,
                'fields' => [
                    ['name' => 'categoria_id', 'label' => 'Categoria', 'type' => 'select', 'rules' => 'required|exists:categorias_componente,id', 'list' => true,
                        'optionsFrom' => [CategoriaComponente::class, 'nome'], 'display' => 'categoria.nome'],
                    ['name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'rules' => 'required|string|max:255', 'list' => true],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'materiais' => [
                'label' => 'Materiais', 'singular' => 'Material', 'model' => Material::class,
                'fields' => [
                    ['name' => 'tipo_id', 'label' => 'Tipo', 'type' => 'select', 'rules' => 'required|exists:tipos_componente,id', 'list' => true,
                        'optionsFrom' => [TipoComponente::class, 'nome'], 'display' => 'tipo.nome'],
                    ['name' => 'descricao', 'label' => 'Descrição', 'type' => 'text', 'rules' => 'required|string|max:255', 'list' => true],
                    // Texto livre: cada tipo mede o que importa (chapa: esp. x larg. x compr.; barra: Ø x compr.).
                    ['name' => 'dimensoes', 'label' => 'Dimensões', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'norma', 'label' => 'Norma', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'processos-fabricacao' => [
                'label' => 'Processos de Fabricação', 'singular' => 'Processo', 'model' => ProcessoFabricacao::class,
                'fields' => [
                    ['name' => 'macro_processo', 'label' => 'Macro Processo', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'processo', 'label' => 'Processo', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'sub_processo', 'label' => 'Sub-processo', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'descricao_servico', 'label' => 'Descrição do Serviço', 'type' => 'textarea', 'rules' => 'nullable|string'],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'componentes-comerciais' => [
                'label' => 'Componentes Comerciais', 'singular' => 'Componente', 'model' => ComponenteComercial::class,
                'fields' => [
                    ['name' => 'categoria_id', 'label' => 'Categoria', 'type' => 'select', 'rules' => 'nullable|exists:categorias_componente,id', 'list' => true,
                        'optionsFrom' => [CategoriaComponente::class, 'nome'], 'display' => 'categoria.nome'],
                    ['name' => 'tipo', 'label' => 'Tipo', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'especificacao', 'label' => 'Especificação', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'padrao', 'label' => 'Padrão', 'type' => 'text', 'rules' => 'nullable|string|max:255', 'list' => true],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'status-engenharia' => [
                'label' => 'Status de Engenharia', 'singular' => 'Status', 'model' => StatusEngenharia::class,
                'fields' => [
                    ['name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'rules' => 'required|string|max:255', 'list' => true],
                    ['name' => 'cor_hex', 'label' => 'Cor', 'type' => 'color', 'rules' => 'required|string|max:7', 'list' => true],
                    ['name' => 'ordem', 'label' => 'Ordem', 'type' => 'number', 'rules' => 'nullable|integer', 'list' => true],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
            'perfis' => [
                'label' => 'Perfis', 'singular' => 'Perfil', 'model' => Perfil::class,
                'fields' => [
                    ['name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'rules' => 'required|string|max:255', 'list' => true],
                    ['name' => 'permissoes', 'label' => 'Permissões (JSON)', 'type' => 'json', 'rules' => 'nullable'],
                    ['name' => 'ativo', 'label' => 'Ativo', 'type' => 'boolean', 'rules' => 'boolean', 'list' => true],
                ],
            ],
        ];
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }
}
