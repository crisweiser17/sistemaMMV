<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotacoes', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->nullable();
            $table->string('numero_cliente')->nullable();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->foreignId('escopo_id')->nullable()->constrained('escopos')->restrictOnDelete();
            $table->date('data_cotacao')->nullable();
            $table->date('prazo_resposta')->nullable();
            $table->string('nf_cliente')->nullable();
            $table->text('observacoes')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cotacao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotacao_id')->constrained('cotacoes')->cascadeOnDelete();
            $table->unsignedInteger('numero_item')->default(1);
            $table->string('cod_mmv')->nullable();
            $table->string('ni')->nullable()->index();
            $table->text('descricao')->nullable();
            $table->decimal('quantidade', 12, 3)->default(0);
            $table->foreignId('unidade_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->string('material_cliente')->nullable();
            $table->text('descricao_cliente')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cotacao_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotacao_id')->constrained('cotacoes')->cascadeOnDelete();
            $table->string('nome_arquivo');
            $table->string('path');
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->string('mime_type')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotacao_anexos');
        Schema::dropIfExists('cotacao_itens');
        Schema::dropIfExists('cotacoes');
    }
};
