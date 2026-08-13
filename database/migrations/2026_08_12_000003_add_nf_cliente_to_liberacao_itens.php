<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NF por item do PI. Itens de recuperacao chegam com Nota Fiscal propria, que pode
 * divergir da NF do cabecalho: quando preenchida aqui, sobrescreve a do PI; quando
 * vazia, o item herda a NF do PI (regra em LiberacaoItem::nf_efetiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liberacao_itens', function (Blueprint $table) {
            $table->string('nf_cliente')->nullable()->after('material_cliente');
        });
    }

    public function down(): void
    {
        Schema::table('liberacao_itens', function (Blueprint $table) {
            $table->dropColumn('nf_cliente');
        });
    }
};
