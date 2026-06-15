<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gerada automaticamente ao criar PI ou cotacao
        Schema::create('demandas', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['liberacao', 'cotacao']);
            $table->unsignedBigInteger('referencia_id'); // id da cotacao ou liberacao
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('status_engenharia')->nullOnDelete();
            $table->date('data_entrada')->nullable();
            $table->date('data_entrega_engenharia')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tipo', 'referencia_id']);
        });

        // Um header por item do PI
        Schema::create('engenharia_headers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demanda_id')->constrained('demandas')->cascadeOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('numero_referencia')->nullable();
            $table->string('nome_item')->nullable();
            $table->date('data_alocacao')->nullable();
            $table->foreignId('status_id')->nullable()->constrained('status_engenharia')->nullOnDelete();
            // referencia opcional ao item de liberacao de origem
            $table->unsignedBigInteger('liberacao_item_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // N linhas por header - cada linha eh uma etapa do processo
        Schema::create('engenharia_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('header_id')->constrained('engenharia_headers')->cascadeOnDelete();
            $table->unsignedInteger('numero_linha')->default(1);
            $table->string('cod_mmv')->nullable();
            $table->text('descricao')->nullable();
            $table->string('arquivo_path')->nullable();
            $table->string('local_referencia')->nullable();
            $table->foreignId('escopo_id')->nullable()->constrained('escopos')->nullOnDelete();
            // tipo_componente: materia_prima | servico | comercial
            $table->string('tipo_componente')->nullable();
            $table->foreignId('categoria_componente_id')->nullable()->constrained('categorias_componente')->nullOnDelete();
            $table->foreignId('tipo_componente_id')->nullable()->constrained('tipos_componente')->nullOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materiais')->nullOnDelete();
            $table->string('mao_de_obra')->nullable();
            $table->decimal('quantidade', 12, 3)->default(0);
            $table->foreignId('unidade_id')->nullable()->constrained('unidades_medida')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->string('fase')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Relacao M:N entre linhas
        Schema::create('engenharia_dependencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('linha_id')->constrained('engenharia_linhas')->cascadeOnDelete();
            $table->foreignId('depende_de_linha_id')->constrained('engenharia_linhas')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['linha_id', 'depende_de_linha_id']);
        });

        // Registro de cada PDF gerado
        Schema::create('outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('header_id')->constrained('engenharia_headers')->cascadeOnDelete();
            $table->foreignId('gerado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('gerado_em')->nullable();
            $table->string('path_pdf')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outputs');
        Schema::dropIfExists('engenharia_dependencias');
        Schema::dropIfExists('engenharia_linhas');
        Schema::dropIfExists('engenharia_headers');
        Schema::dropIfExists('demandas');
    }
};
