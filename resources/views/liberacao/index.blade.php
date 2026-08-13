@extends('layouts.app')
@section('title', 'Liberações (PI)')

@section('content')
<x-card title="Liberações (PI)">
    <x-slot:actions>
        @can('editar', 'liberacao')<x-button :href="route('liberacao.create')">+ Novo PI</x-button>@endcan
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
        <x-input name="busca" placeholder="Buscar nº PI ou NF" :value="request('busca')" class="w-56" />
        <x-button type="submit" variant="secondary">Filtrar</x-button>
    </form>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b">
                <th class="py-2 pr-4">PI</th><th class="py-2 pr-4">PC</th><th class="py-2 pr-4">Cliente</th>
                <th class="py-2 pr-4">NF</th><th class="py-2 pr-4">Data</th><th class="py-2 pr-4">Prazo (dias)</th><th class="py-2 pr-4">Itens</th>
                <th class="py-2 text-right">Ações</th>
            </tr></thead>
            <tbody class="divide-y">
                @forelse ($liberacoes as $l)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2 pr-4 font-medium">{{ $l->numero_pi ?? '#'.$l->id }}</td>
                        <td class="py-2 pr-4">{{ $l->numero_pc ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $l->cliente_com_unidade ?? '—' }}</td>
                        {{-- NF do PI; "+N" quando ha itens com NF propria diferente (detalhe no hover). --}}
                        @php $nf = $l->resumoNf(); @endphp
                        <td class="py-2 pr-4 whitespace-nowrap" @if ($nf['detalhe']) title="{{ $nf['detalhe'] }}" @endif>
                            {{ $nf['rotulo'] ?? '—' }}
                            @if ($nf['extras'] > 0)
                                <span class="ml-1 text-xs text-accent-700">+{{ $nf['extras'] }}</span>
                            @endif
                        </td>
                        <td class="py-2 pr-4">{{ optional($l->data_pedido)->format('d/m/Y') ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $l->prazo_entrega_dias ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $l->itens->count() }}</td>
                        <td class="py-2 text-right">
                            <a href="{{ route('liberacao.show', $l) }}" class="text-accent-700 hover:underline">Ver</a>
                            @can('editar', 'liberacao')<a href="{{ route('liberacao.edit', $l) }}" class="text-accent-700 hover:underline ml-3">Editar</a>@endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-6 text-center text-gray-400">Nenhum PI.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $liberacoes->links() }}</div>
</x-card>
@endsection
