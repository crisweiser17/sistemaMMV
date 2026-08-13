<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tabelas de negocio que passam a apontar para a unidade do cliente. */
    private const TABELAS_VINCULADAS = ['liberacoes', 'cotacoes', 'engenharia_headers'];

    public function up(): void
    {
        // Um cliente tem N unidades (ex.: Suzano SA -> Tres Lagoas, Ribas do Rio Pardo).
        Schema::create('cliente_unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('nome');
            $table->string('codigo')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (self::TABELAS_VINCULADAS as $tabela) {
            if (Schema::hasColumn($tabela, 'unidade_id')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) {
                $table->foreignId('unidade_id')->nullable()->after('cliente_id')
                    ->constrained('cliente_unidades')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABELAS_VINCULADAS) as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropConstrainedForeignId('unidade_id');
            });
        }

        Schema::dropIfExists('cliente_unidades');
    }
};
