<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Perfil extends Model
{
    use SoftDeletes;

    protected $table = 'perfis';

    /** Nome do perfil que administra os demais (e o unico que edita permissoes). */
    public const ADMINISTRADOR = 'Administrador';

    /**
     * Modulos controlados pelas permissoes, na ordem exibida na tela de perfis.
     * A chave e a usada em @can('ver'|'editar', '<modulo>').
     */
    public const MODULOS = [
        'demandas' => ['label' => 'Controle de Demandas', 'descricao' => 'Painel de demandas, alocação e status.'],
        'cotacao' => ['label' => 'Cotação', 'descricao' => 'Cadastro e acompanhamento de cotações.'],
        'liberacao' => ['label' => 'Liberação (PI)', 'descricao' => 'Pedidos internos, itens e anexos.'],
        'engenharia' => ['label' => 'Engenharia', 'descricao' => 'Estrutura dos itens, arquivos e cronograma.'],
        'admin' => ['label' => 'Cadastros de Apoio', 'descricao' => 'Clientes, materiais, processos e demais cadastros.'],
    ];

    /** Niveis de acesso; 'nenhum' nao e gravado no JSON (ausencia = sem acesso). */
    public const NIVEIS = [
        'nenhum' => 'Sem acesso',
        'ver' => 'Ver',
        'editar' => 'Editar',
    ];

    protected $fillable = ['nome', 'permissoes', 'ativo'];

    protected $casts = [
        'permissoes' => 'array',
        'ativo' => 'boolean',
    ];

    public function ehAdministrador(): bool
    {
        return $this->nome === self::ADMINISTRADOR;
    }

    /** Nivel do perfil num modulo: 'nenhum', 'ver' ou 'editar'. */
    public function nivelEm(string $modulo): string
    {
        $nivel = ($this->permissoes ?? [])[$modulo] ?? null;

        return array_key_exists((string) $nivel, self::NIVEIS) ? $nivel : 'nenhum';
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
