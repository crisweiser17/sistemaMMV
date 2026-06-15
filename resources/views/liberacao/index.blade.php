@extends('layouts.app')
@section('title', 'Liberações (PI)')

@section('content')
<x-card title="Liberações (PI)">
    <x-slot:actions>
        @can('editar', 'liberacao')<x-button :href="route('liberacao.create')">+ Novo PI</x-button>@endcan
    </x-slot:actions>

    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <x-select name="cliente_id" :options="$clientes->toArray()" :value="request('cliente_id')" placeholder="Todos os clientes" class="w-56" />
        <x-input name="busca" placeholder="Buscar nº PI" :value="request('busca')" class="w-56" />
        <x-button type="submit" variant="secondary">Filtrar</x-button>
    </form>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b">
                <th class="py-2 pr-4">PI</th><th class="py-2 pr-4">PC</th><th class="py-2 pr-4">Cliente</th>
                <th class="py-2 pr-4">Data</th><th class="py-2 pr-4">Prazo (dias)</th><th class="py-2 pr-4">Itens</th>
                <th class="py-2 text-right">Ações</th>
            </tr></thead>
            <tbody class="divide-y">
                @forelse ($liberacoes as $l)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2 pr-4 font-medium">{{ $l->numero_pi ?? '#'.$l->id }}</td>
                        <td class="py-2 pr-4">{{ $l->numero_pc ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $l->cliente?->nome ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ optional($l->data_pedido)->format('d/m/Y') ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $l->prazo_entrega_dias ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $l->itens()->count() }}</td>
                        <td class="py-2 text-right">
                            <a href="{{ route('liberacao.show', $l) }}" class="text-accent-700 hover:underline">Ver</a>
                            @can('editar', 'liberacao')<a href="{{ route('liberacao.edit', $l) }}" class="text-accent-700 hover:underline ml-3">Editar</a>@endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-400">Nenhum PI.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $liberacoes->links() }}</div>
</x-card>
@endsection
