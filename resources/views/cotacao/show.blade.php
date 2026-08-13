@extends('layouts.app')
@section('title', 'Cotação ' . ($cotacao->numero ?? '#'.$cotacao->id))

@section('content')
<x-card :title="'Cotação ' . ($cotacao->numero ?? '#'.$cotacao->id)" class="mb-4">
    <x-slot:actions>
        @can('editar', 'cotacao')<x-button :href="route('cotacao.edit', $cotacao)" variant="secondary">Editar</x-button>@endcan
    </x-slot:actions>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div><div class="text-gray-500">Cliente</div><div>{{ $cotacao->cliente_com_unidade ?? '—' }}</div></div>
        <div><div class="text-gray-500">Escopo</div><div>{{ $cotacao->escopo?->descricao ?? '—' }}</div></div>
        <div><div class="text-gray-500">Data</div><div>{{ optional($cotacao->data_cotacao)->format('d/m/Y') ?? '—' }}</div></div>
        <div><div class="text-gray-500">NF Cliente</div><div>{{ $cotacao->nf_cliente ?? '—' }}</div></div>
        <div class="col-span-2 md:col-span-4"><div class="text-gray-500">Observações</div><div>{{ $cotacao->observacoes ?? '—' }}</div></div>
    </div>
</x-card>

<x-card title="Itens" class="mb-4">
    <table class="min-w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b">
            <th class="py-2 pr-4">#</th><th class="py-2 pr-4">Cód. MMV</th><th class="py-2 pr-4">NI</th>
            <th class="py-2 pr-4">Descrição</th><th class="py-2 pr-4">Qtd</th><th class="py-2 pr-4">Un.</th>
        </tr></thead>
        <tbody class="divide-y">
            @foreach ($cotacao->itens as $i)
                <tr><td class="py-2 pr-4">{{ $i->numero_item }}</td><td class="py-2 pr-4">{{ $i->cod_mmv }}</td>
                <td class="py-2 pr-4">{{ $i->ni }}</td><td class="py-2 pr-4">{{ $i->descricao }}</td>
                <td class="py-2 pr-4">{{ $i->quantidade }}</td><td class="py-2 pr-4">{{ $i->unidade?->sigla }}</td></tr>
            @endforeach
        </tbody>
    </table>
</x-card>

<x-card title="Anexos">
    @can('editar', 'cotacao')
        <form method="POST" action="{{ route('cotacao.anexo', $cotacao) }}" enctype="multipart/form-data" class="flex items-end gap-3 mb-4">
            @csrf
            <input type="file" name="arquivo" required class="text-sm" accept="{{ \App\Services\AnexoService::accept() }}">
            <x-button type="submit" variant="secondary">Enviar anexo</x-button>
            <span class="text-xs text-gray-400">{{ \App\Services\AnexoService::extensoesLegiveis() }} · até {{ \App\Services\AnexoService::limiteMb() }} MB</span>
        </form>
    @endcan
    <ul class="text-sm divide-y">
        @forelse ($cotacao->anexos as $a)
            <li class="py-2 flex justify-between items-center gap-3">
                <a href="{{ route('cotacao.anexo.ver', [$cotacao, $a]) }}" target="_blank" rel="noopener" class="text-accent-700 hover:underline inline-flex items-center gap-1">
                    📎 {{ $a->nome_arquivo }} <span class="text-gray-400">({{ round(($a->tamanho ?? 0)/1024/1024, 2) }} MB)</span>
                </a>
                @can('editar', 'cotacao')
                    <form method="POST" action="{{ route('cotacao.anexo.remove', [$cotacao, $a]) }}" onsubmit="return confirm('Remover anexo?')">
                        @csrf @method('DELETE')<button class="text-red-600 hover:underline">Remover</button>
                    </form>
                @endcan
            </li>
        @empty
            <li class="py-3 text-gray-400">Nenhum anexo.</li>
        @endforelse
    </ul>
</x-card>
@endsection
