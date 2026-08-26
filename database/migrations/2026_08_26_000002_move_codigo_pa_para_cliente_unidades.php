<?php

use App\Services\ClienteUnidadeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O codigo e POR UNIDADE, nao por cliente (Suzano Tres Lagoas 10, Suzano
     * Jacarei 25, Klabin Ortigueira 32...). clientes.codigo_pa deixou de fazer
     * sentido: o valor vai para a unidade quando existe um destino obvio
     * (cliente com exatamente UMA unidade, ainda sem codigo) e a coluna sai.
     *
     * Idempotente: o guard Schema::hasColumn faz a segunda execucao ser um no-op.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('clientes', 'codigo_pa')) {
            return;
        }

        $resultado = app(ClienteUnidadeService::class)->moverCodigoPaParaUnidades();

        // Ambiguidade nao se adivinha: o que sobrou vai para o log para o operador preencher.
        foreach ($resultado['pendentes'] as $pendente) {
            Log::warning('[codigo_pa] '.$pendente);
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('codigo_pa');
        });
    }

    /** Recria a coluna vazia; os valores ja vivem nas unidades. */
    public function down(): void
    {
        if (Schema::hasColumn('clientes', 'codigo_pa')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->string('codigo_pa')->nullable()->after('nome');
        });
    }
};
