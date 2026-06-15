<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outputs', function (Blueprint $table) {
            // O documento PI passa a ser por DEMANDA (agrupa todos os itens), nao mais por header.
            $table->foreignId('demanda_id')->nullable()->after('id')->constrained('demandas')->cascadeOnDelete();
            $table->foreignId('header_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('outputs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demanda_id');
        });
    }
};
