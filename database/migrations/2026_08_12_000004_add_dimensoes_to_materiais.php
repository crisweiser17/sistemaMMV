<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materiais', function (Blueprint $table) {
            // Texto livre de proposito: cada tipo de material mede o que importa para ele
            // (chapa = espessura x largura x comprimento, barra = diametro x comprimento,
            // tubo = diametro x parede x comprimento). Colunas rigidas ficariam nulas na maioria dos casos.
            $table->string('dimensoes')->nullable()->after('descricao');
        });
    }

    public function down(): void
    {
        Schema::table('materiais', function (Blueprint $table) {
            $table->dropColumn('dimensoes');
        });
    }
};
