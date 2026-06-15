<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password', 'perfil_id', 'ativo'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ativo' => 'boolean',
        ];
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /** Verifica se o usuario possui um dos perfis informados (por nome). */
    public function temPerfil(string ...$perfis): bool
    {
        return $this->perfil && in_array($this->perfil->nome, $perfis, true);
    }

    /** Verifica permissao granular declarada no JSON do perfil. */
    public function podeEm(string $modulo, string $acao = 'ver'): bool
    {
        $permissoes = $this->perfil?->permissoes ?? [];
        $nivel = $permissoes[$modulo] ?? null;

        return match ($acao) {
            'ver' => in_array($nivel, ['ver', 'editar'], true),
            'editar' => $nivel === 'editar',
            default => false,
        };
    }
}
