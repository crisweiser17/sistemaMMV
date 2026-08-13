<?php

use App\Services\ClienteUnidadeService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Migracao de DADOS: cada clientes.unidade (texto livre) vira uma linha de
     * cliente_unidades e os PIs/cotacoes/headers daquele cliente passam a
     * apontar para ela. A partir daqui clientes.unidade fica obsoleta —
     * nenhuma tela, service ou seeder le mais esse campo.
     */
    public function up(): void
    {
        app(ClienteUnidadeService::class)->migrarLegado();
    }

    /** Sem down: a coluna legada continua intacta em clientes, nada se perde. */
    public function down(): void
    {
        //
    }
};
