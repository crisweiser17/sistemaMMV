<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engenharia_linhas', function (Blueprint $table) {
            // Duracao da etapa em dias uteis; alimenta o calculo do Gantt e do prazo de fabricacao.
            $table->unsignedInteger('duracao_dias')->default(2)->after('quantidade');
        });
    }

    public function down(): void
    {
        Schema::table('engenharia_linhas', function (Blueprint $table) {
            $table->dropColumn('duracao_dias');
        });
    }
};
