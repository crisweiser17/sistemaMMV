<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Gates de permissao por modulo, alimentados pelo JSON de permissoes do perfil.
        // Uso: @can('ver', 'demandas') / @can('editar', 'engenharia') / $user->can('editar', 'cotacao')
        Gate::define('ver', fn (User $user, string $modulo) => $user->podeEm($modulo, 'ver'));
        Gate::define('editar', fn (User $user, string $modulo) => $user->podeEm($modulo, 'editar'));
    }
}
