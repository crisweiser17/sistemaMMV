<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfis', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->json('permissoes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('codigo_pa')->nullable();
            $table->string('unidade')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('escopos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->nullable();
            $table->string('descricao');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('sigla', 20);
            $table->string('descricao')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Nivel 1 da hierarquia de componentes
        Schema::create('categorias_componente', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->enum('tipo', ['materia_prima', 'servico', 'comercial']);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Nivel 2
        Schema::create('tipos_componente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias_componente')->restrictOnDelete();
            $table->string('nome');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Nivel 3 - ex.: ASTM A36
        Schema::create('materiais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_id')->constrained('tipos_componente')->restrictOnDelete();
            $table->string('descricao');
            $table->string('norma')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // 4 niveis hierarquicos
        Schema::create('processos_fabricacao', function (Blueprint $table) {
            $table->id();
            $table->string('macro_processo')->nullable();
            $table->string('processo')->nullable();
            $table->string('sub_processo')->nullable();
            $table->text('descricao_servico')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('componentes_comerciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias_componente')->nullOnDelete();
            $table->string('tipo')->nullable();
            $table->string('especificacao')->nullable();
            $table->string('padrao')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('status_engenharia', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('cor_hex', 7)->default('#6b7280');
            $table->unsignedInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_engenharia');
        Schema::dropIfExists('componentes_comerciais');
        Schema::dropIfExists('processos_fabricacao');
        Schema::dropIfExists('materiais');
        Schema::dropIfExists('tipos_componente');
        Schema::dropIfExists('categorias_componente');
        Schema::dropIfExists('unidades_medida');
        Schema::dropIfExists('escopos');
        Schema::dropIfExists('clientes');
        Schema::dropIfExists('perfis');
    }
};
