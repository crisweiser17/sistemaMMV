@extends('layouts.app')
@section('title', 'Controle de Demandas')

@section('content')
<div x-data="Object.assign(
        liveResource({ url: '{{ route('demandas.data') }}', channel: 'demandas', events: ['.demanda.atualizada'] }),
        {
            filtros: { responsavel_id: '', status_id: '', tipo: '', busca: '' },
            podeEditar: {{ auth()->user()->can('editar', 'demandas') ? 'true' : 'false' }},
            alocarModal: { aberto: false, demandaId: null, responsavel_id: '' },
            expandida: null,
            aplicar() { this.load(this.filtros); },
            abrirAlocar(id) { this.alocarModal = { aberto: true, demandaId: id, responsavel_id: '' }; },
            async confirmarAlocacao() {
                try {
                    const r = await window.mmvFetch(`/demandas/${this.alocarModal.demandaId}/alocar`, {
                        method: 'PUT', body: { responsavel_id: this.alocarModal.responsavel_id }
                    });
                    window.mmvToast(r.message || 'Demanda alocada.');
                    this.alocarModal.aberto = false;
                    this.load(this.filtros);
                } catch (e) { window.mmvToast('Falha ao alocar.', 'error'); }
            }
        }
    )">

    <x-card>
        <x-slot:title>
            Controle de Demandas
            <span class="ml-2 text-xs font-normal text-emerald-600" x-show="!loading">⟳ tempo real</span>
            <span class="ml-2 text-xs font-normal text-gray-400" x-show="loading">carregando…</span>
        </x-slot:title>

        @if (auth()->user()->can('editar', 'demandas'))
            <x-slot:actions>
                {{-- Demanda nasce de uma Cotação ou de um PI; o botão abre essas duas entradas. --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <x-button @click="open = !open">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Nova Demanda
                    </x-button>
                    <div x-show="open" x-cloak x-transition.origin.top.right
                         class="absolute right-0 mt-1 w-56 bg-white rounded-md shadow-lg border border-gray-200 py-1 z-20">
                        <a href="{{ route('cotacao.create') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Nova Cotação</a>
                        <a href="{{ route('liberacao.create') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Nova Liberação (PI)</a>
                    </div>
                </div>
            </x-slot:actions>
        @endif

        <div class="flex flex-wrap gap-3 mb-4">
            <select x-model="filtros.responsavel_id" @change="aplicar()" class="rounded-md border-gray-300 text-sm">
                <option value="">Todos responsáveis</option>
                @foreach ($responsaveis as $id => $nome)<option value="{{ $id }}">{{ $nome }}</option>@endforeach
            </select>
            <select x-model="filtros.status_id" @change="aplicar()" class="rounded-md border-gray-300 text-sm">
                <option value="">Todos status</option>
                @foreach ($statuses as $s)<option value="{{ $s->id }}">{{ $s->nome }}</option>@endforeach
            </select>
            <select x-model="filtros.tipo" @change="aplicar()" class="rounded-md border-gray-300 text-sm">
                <option value="">Todos tipos</option>
                <option value="liberacao">Liberação</option>
                <option value="cotacao">Cotação</option>
            </select>
            <input x-model.debounce.400ms="filtros.busca" @input="aplicar()" placeholder="Buscar nº PI/cotação"
                   class="rounded-md border-gray-300 text-sm w-56">
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b">
                    <th class="py-2 pr-3"></th><th class="py-2 pr-3">Entrada</th><th class="py-2 pr-3">Tipo</th>
                    <th class="py-2 pr-3">Nº Ref.</th><th class="py-2 pr-3">Itens</th>
                    <th class="py-2 pr-3">Prazo Cotação</th><th class="py-2 pr-3">Prazo Fabricação</th>
                    <th class="py-2 pr-3">Cliente</th><th class="py-2 pr-3">Responsável</th><th class="py-2 pr-3">Escopo</th>
                    <th class="py-2 pr-3">Entrega Eng.</th><th class="py-2 pr-3">Status</th><th class="py-2 text-right">Ações</th>
                </tr></thead>

                {{-- Cada demanda eh um <tbody> (HTML valido), permitindo linha principal + linha expandida --}}
                <template x-for="d in items" :key="d.id">
                    <tbody class="border-b">
                        <tr class="hover:bg-gray-50">
                            <td class="py-2 pr-3"><button @click="expandida = expandida === d.id ? null : d.id" class="text-gray-400 hover:text-gray-700" x-text="expandida === d.id ? '▾' : '▸'"></button></td>
                            <td class="py-2 pr-3" x-text="d.data_entrada"></td>
                            <td class="py-2 pr-3 capitalize" x-text="d.tipo"></td>
                            <td class="py-2 pr-3 font-medium" x-text="d.numero_referencia"></td>
                            <td class="py-2 pr-3" x-text="d.qtd_itens"></td>
                            <td class="py-2 pr-3" x-text="d.prazo_cotacao != null ? d.prazo_cotacao + ' dias' : '—'"></td>
                            <td class="py-2 pr-3" x-text="d.prazo_fabricacao != null ? d.prazo_fabricacao + ' dias' : '—'"></td>
                            <td class="py-2 pr-3" x-text="d.cliente ?? '—'"></td>
                            <td class="py-2 pr-3" x-text="d.responsavel ?? '—'"></td>
                            <td class="py-2 pr-3" x-text="d.escopo ?? '—'"></td>
                            <td class="py-2 pr-3" x-text="d.data_entrega_eng ?? '—'"></td>
                            <td class="py-2 pr-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" x-show="d.status"
                                      :style="d.status ? `background:${d.status.cor_hex}1a;color:${d.status.cor_hex};border:1px solid ${d.status.cor_hex}40` : ''"
                                      x-text="d.status?.nome"></span>
                            </td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <button x-show="podeEditar" @click="abrirAlocar(d.id)" class="text-accent-700 hover:underline">Alocar</button>
                                <span x-show="!podeEditar" class="text-gray-300">—</span>
                            </td>
                        </tr>
                        <tr x-show="expandida === d.id" class="bg-gray-50">
                            <td></td>
                            <td colspan="12" class="py-3">
                                <div class="text-xs text-gray-500 mb-1">Itens</div>
                                <table class="w-full text-xs">
                                    <thead><tr class="text-gray-400 text-left"><th class="pr-4">Item</th><th class="pr-4">Cód. MMV</th><th class="pr-4">Descrição</th><th>Qtd</th></tr></thead>
                                    <tbody>
                                        <template x-for="(it, i) in d.itens" :key="i">
                                            <tr><td class="pr-4" x-text="it.numero_item"></td><td class="pr-4" x-text="it.cod_mmv"></td><td class="pr-4" x-text="it.descricao"></td><td x-text="it.quantidade"></td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </tbody>
                </template>

                <tbody x-show="!items.length && !loading"><tr><td colspan="13" class="py-6 text-center text-gray-400">Nenhuma demanda.</td></tr></tbody>
            </table>
        </div>
    </x-card>

    <x-modal x-model="alocarModal.aberto">
        <x-slot:header>Alocar responsável</x-slot:header>
        <p class="text-sm text-gray-600 mb-3">Ao alocar, um header de engenharia será gerado para cada item do PI/cotação.</p>
        <select x-model="alocarModal.responsavel_id" class="w-full rounded-md border-gray-300 text-sm">
            <option value="">Selecione o responsável…</option>
            @foreach ($responsaveis as $id => $nome)<option value="{{ $id }}">{{ $nome }}</option>@endforeach
        </select>
        <x-slot:footer>
            <x-button variant="secondary" @click="alocarModal.aberto = false">Cancelar</x-button>
            <x-button @click="confirmarAlocacao()" x-bind:disabled="!alocarModal.responsavel_id">Confirmar alocação</x-button>
        </x-slot:footer>
    </x-modal>
</div>
@endsection
