<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liberacoes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_pi')->nullable();
            $table->string('numero_pc')->nullable();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->foreignId('escopo_id')->nullable()->constrained('escopos')->restrictOnDelete();
            $table->date('data_pedido')->nullable();
            $table->string('nf_cliente')->nullable();
            $table->unsignedInteger('prazo_entrega_dias')->nullable();
            $table->date('data_entrega_cliente')->nullable();
            $table->text('observacoes')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('liberacao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liberacao_id')->constrained('liberacoes')->cascadeOnDelete();
            $table->unsignedInteger('numero_item')->default(1);
            $table->string('cod_mmv')->nullable();
            $table->string('ni')->nullable()->index();
            $table->text('descricao')->nullable();
            $table->decimal('quantidade', 12, 3)->default(0);
            $table->foreignId('unidade_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->string('material_cliente')->nullable();
            $table->unsignedInteger('prazo_entrega_item')->nullable();
            $table->text('descricao_cliente')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('liberacao_itens_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liberacao_item_id')->constrained('liberacao_itens')->cascadeOnDelete();
            $table->string('nome_arquivo');
            $table->string('path');
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('liberacao_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liberacao_id')->constrained('liberacoes')->cascadeOnDelete();
            $table->string('nome_arquivo');
            $table->string('path');
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liberacao_anexos');
        Schema::dropIfExists('liberacao_itens_anexos');
        Schema::dropIfExists('liberacao_itens');
        Schema::dropIfExists('liberacoes');
    }
};
