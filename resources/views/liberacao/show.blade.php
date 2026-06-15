@extends('layouts.app')
@section('title', 'PI ' . ($liberacao->numero_pi ?? '#'.$liberacao->id))

@section('content')
<x-card :title="'PI ' . ($liberacao->numero_pi ?? '#'.$liberacao->id)" class="mb-4">
    <x-slot:actions>
        @can('editar', 'liberacao')<x-button :href="route('liberacao.edit', $liberacao)" variant="secondary">Editar</x-button>@endcan
    </x-slot:actions>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div><div class="text-gray-500">Cliente</div><div>{{ $liberacao->cliente?->nome ?? '—' }}</div></div>
        <div><div class="text-gray-500">PC</div><div>{{ $liberacao->numero_pc ?? '—' }}</div></div>
        <div><div class="text-gray-500">Data Pedido</div><div>{{ optional($liberacao->data_pedido)->format('d/m/Y') ?? '—' }}</div></div>
        <div><div class="text-gray-500">Prazo (dias)</div><div>{{ $liberacao->prazo_entrega_dias ?? '—' }}</div></div>
    </div>
</x-card>

<x-card title="Itens" class="mb-4">
    @foreach ($liberacao->itens as $i)
        <div class="border-b py-3">
            <div class="flex justify-between text-sm">
                <div><b>#{{ $i->numero_item }}</b> · {{ $i->cod_mmv }} · {{ $i->descricao }}</div>
                <div class="text-gray-500">{{ $i->quantidade }} {{ $i->unidade?->sigla }} · prazo {{ $i->prazo_entrega_item ?? '—' }}d</div>
            </div>
            @can('editar', 'liberacao')
                <form method="POST" action="{{ route('liberacao.item.anexo', [$liberacao, $i]) }}" enctype="multipart/form-data" class="flex items-center gap-2 mt-2">
                    @csrf<input type="file" name="arquivo" required class="text-xs">
                    <button class="text-xs text-accent-700 hover:underline">Anexar ao item</button>
                </form>
            @endcan
            @if ($i->anexos->count())
                <ul class="text-xs mt-1 space-y-1">
                    @foreach ($i->anexos as $a)
                        <li class="flex items-center gap-2">
                            <a href="{{ route('liberacao.item.anexo.ver', [$liberacao, $a]) }}" target="_blank" rel="noopener" class="text-accent-700 hover:underline">📎 {{ $a->nome_arquivo }}</a>
                            @can('editar', 'liberacao')
                                <form method="POST" action="{{ route('liberacao.item.anexo.remove', [$liberacao, $a]) }}" onsubmit="return confirm('Remover anexo?')">
                                    @csrf @method('DELETE')<button class="text-red-600 hover:underline">Remover</button>
                                </form>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</x-card>

<x-card title="Anexos gerais do PI">
    @can('editar', 'liberacao')
        <form method="POST" action="{{ route('liberacao.anexo', $liberacao) }}" enctype="multipart/form-data" class="flex items-end gap-3 mb-4">
            @csrf<input type="file" name="arquivo" required class="text-sm">
            <x-button type="submit" variant="secondary">Enviar anexo</x-button>
        </form>
    @endcan
    <ul class="text-sm divide-y">
        @forelse ($liberacao->anexos as $a)
            <li class="py-2 flex justify-between items-center gap-3">
                <a href="{{ route('liberacao.anexo.ver', [$liberacao, $a]) }}" target="_blank" rel="noopener" class="text-accent-700 hover:underline inline-flex items-center gap-1">
                    📎 {{ $a->nome_arquivo }} <span class="text-gray-400">({{ round(($a->tamanho ?? 0)/1024/1024, 2) }} MB)</span>
                </a>
                @can('editar', 'liberacao')
                    <form method="POST" action="{{ route('liberacao.anexo.remove', [$liberacao, $a]) }}" onsubmit="return confirm('Remover anexo?')">
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
