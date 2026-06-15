<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liga o header ao item da cotacao de origem (espelha liberacao_item_id).
        if (! Schema::hasColumn('engenharia_headers', 'cotacao_item_id')) {
            Schema::table('engenharia_headers', function (Blueprint $table) {
                $table->unsignedBigInteger('cotacao_item_id')->nullable()->after('liberacao_item_id');
            });
        }

        // Backfill best-effort para headers ja existentes (demandas tipo cotacao):
        // casa pelo nome_item == descricao do item dentro da mesma cotacao.
        $headers = DB::table('engenharia_headers as h')
            ->join('demandas as d', 'd.id', '=', 'h.demanda_id')
            ->whereNull('h.cotacao_item_id')
            ->where('d.tipo', 'cotacao')
            ->whereNull('h.deleted_at')
            ->select('h.id', 'h.nome_item', 'd.referencia_id')
            ->get();

        foreach ($headers as $header) {
            $itemId = DB::table('cotacao_itens')
                ->where('cotacao_id', $header->referencia_id)
                ->where('descricao', $header->nome_item)
                ->whereNull('deleted_at')
                ->value('id');

            if ($itemId) {
                DB::table('engenharia_headers')->where('id', $header->id)->update(['cotacao_item_id' => $itemId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('engenharia_headers', function (Blueprint $table) {
            $table->dropColumn('cotacao_item_id');
        });
    }
};
