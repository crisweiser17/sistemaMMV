@extends('layouts.app')
@section('title', 'Cotações')

@section('content')
<x-card title="Cotações">
    <x-slot:actions>
        @can('editar', 'cotacao')
            <x-button :href="route('cotacao.create')">+ Nova Cotação</x-button>
        @endcan
    </x-slot:actions>

    {{-- O select de unidade acompanha o cliente escolhido (lookup JSON). --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-4"
          x-data="Object.assign(unidadesDoCliente(), {
              unidadeAtual: @js(request('unidade_id')),
              init() { return this.carregarUnidades(@js(request('cliente_id'))); }
          })">
        <x-select name="cliente_id" :options="$clientes->toArray()" :value="request('cliente_id')" placeholder="Todos os clientes" class="w-56"
                  @change="unidadeAtual = ''; carregarUnidades($event.target.value)" />
        <select name="unidade_id" class="w-56 rounded-md border-gray-300 shadow-sm focus:border-accent-500 focus:ring-accent-500 text-sm"
                :disabled="!unidades.length">
            <option value="">Todas as unidades</option>
            <template x-for="u in unidades" :key="u.id">
                <option :value="u.id" :selected="String(u.id) === String(unidadeAtual)" x-text="u.nome"></option>
            </template>
        </select>
        <x-input name="busca" placeholder="Buscar nº cotação" :value="request('busca')" class="w-56" />
        <x-button type="submit" variant="secondary">Filtrar</x-button>
    </form>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b">
                <th class="py-2 pr-4">Número</th><th class="py-2 pr-4">Cliente</th>
                <th class="py-2 pr-4">Escopo</th><th class="py-2 pr-4">Data</th>
                <th class="py-2 pr-4">Itens</th><th class="py-2 text-right">Ações</th>
            </tr></thead>
            <tbody class="divide-y">
                @forelse ($cotacoes as $c)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2 pr-4 font-medium">{{ $c->numero ?? '#'.$c->id }}</td>
                        <td class="py-2 pr-4">{{ $c->cliente_com_unidade ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $c->escopo?->descricao ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ optional($c->data_cotacao)->format('d/m/Y') ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $c->itens()->count() }}</td>
                        <td class="py-2 text-right">
                            <a href="{{ route('cotacao.show', $c) }}" class="text-accent-700 hover:underline">Ver</a>
                            @can('editar', 'cotacao')
                                <a href="{{ route('cotacao.edit', $c) }}" class="text-accent-700 hover:underline ml-3">Editar</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-gray-400">Nenhuma cotação.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $cotacoes->links() }}</div>
</x-card>
@endsection
