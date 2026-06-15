<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('perfil_id')->nullable()->after('email')->constrained('perfis')->nullOnDelete();
            $table->boolean('ativo')->default(true)->after('perfil_id');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('perfil_id');
            $table->dropColumn(['ativo', 'deleted_at']);
        });
    }
};
