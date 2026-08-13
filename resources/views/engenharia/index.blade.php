@extends('layouts.app')
@section('title', 'Engenharia')

@section('content')
<div x-data="Object.assign(
        liveResource({ url: '{{ route('engenharia.data') }}', channel: 'demandas', events: ['.demanda.atualizada'] }),
        unidadesDoCliente(),
        {
            filtros: { busca: '', cliente_id: '', unidade_id: '', status_id: '', tipo: '', responsavel_id: '' },
            expandidas: {},
            get filtrando() { return Object.values(this.filtros).some(v => v !== '' && v !== null); },
            aplicar() { this.load(this.filtros); },
            limpar() { this.filtros = { busca: '', cliente_id: '', unidade_id: '', status_id: '', tipo: '', responsavel_id: '' }; this.unidades = []; this.load(this.filtros); },
            // Trocar de cliente zera a unidade e recarrega as opcoes daquele cliente.
            async trocarCliente() {
                this.filtros.unidade_id = '';
                await this.carregarUnidades(this.filtros.cliente_id);
                this.aplicar();
            },
            toggle(id) { this.expandidas[id] = !this.expandidas[id]; },
            aberto(id) { return this.filtrando || !!this.expandidas[id]; }
        }
    )">

    <x-card class="mb-4">
        <div class="flex flex-wrap items-end gap-3">
            <input x-model.debounce.400ms="filtros.busca" @input="aplicar()" placeholder="Buscar nº da cotação/PI ou item"
                   class="rounded-md border-gray-300 text-sm grow min-w-[220px]">
            <select x-model="filtros.cliente_id" @change="trocarCliente()" class="rounded-md border-gray-300 text-sm">
                <option value="">Todos clientes</option>
                @foreach ($clientes as $id => $nome)<option value="{{ $id }}">{{ $nome }}</option>@endforeach
            </select>
            {{-- O select de unidade reflete o cliente selecionado (lookup JSON). --}}
            <select x-model="filtros.unidade_id" @change="aplicar()" :disabled="!unidades.length" class="rounded-md border-gray-300 text-sm">
                <option value="">Todas unidades</option>
                <template x-for="u in unidades" :key="u.id">
                    <option :value="u.id" x-text="u.nome"></option>
                </template>
            </select>
            <select x-model="filtros.status_id" @change="aplicar()" class="rounded-md border-gray-300 text-sm">
                <option value="">Todos status</option>
                @foreach ($statuses as $s)<option value="{{ $s->id }}">{{ $s->nome }}</option>@endforeach
            </select>
            <select x-model="filtros.tipo" @change="aplicar()" class="rounded-md border-gray-300 text-sm">
                <option value="">Todos tipos</option>
                <option value="cotacao">Cotação</option>
                <option value="liberacao">Liberação</option>
            </select>
            @if ($responsaveis)
                <select x-model="filtros.responsavel_id" @change="aplicar()" class="rounded-md border-gray-300 text-sm">
                    <option value="">Todos responsáveis</option>
                    @foreach ($responsaveis as $id => $nome)<option value="{{ $id }}">{{ $nome }}</option>@endforeach
                </select>
            @endif
            <button @click="limpar()" x-show="filtrando" x-cloak class="text-sm text-gray-500 hover:underline pb-2">Limpar</button>
            <span class="ml-auto text-xs self-center" :class="loading ? 'text-gray-400' : 'text-emerald-600'"
                  x-text="loading ? 'carregando…' : '⟳ tempo real'"></span>
        </div>
    </x-card>

    {{-- Cards reativos por cotação/demanda --}}
    <template x-for="d in items" :key="d.id">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-4 p-5">
            <div class="flex items-center justify-between">
                <button type="button" @click="toggle(d.id)" class="flex items-center gap-3 text-left">
                    <span class="text-gray-400 text-xs w-3" x-text="aberto(d.id) ? '▼' : '▶'"></span>
                    <span>
                        <span class="block text-base font-semibold text-gray-800">
                            <span x-text="d.numero_referencia"></span>
                            {{-- Processo que mudou depois do ultimo PDF do PI. --}}
                            <span x-show="d.alterado" x-cloak
                                  class="ml-1 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold bg-red-100 text-red-700 border border-red-300 align-middle">ALTERADO</span>
                        </span>
                        <span class="block text-sm text-gray-500">
                            <span x-text="d.cliente ?? '—'"></span>
                            · <span class="capitalize" x-text="d.tipo"></span>
                            · <span x-text="d.qtd_itens + (d.qtd_itens === 1 ? ' item' : ' itens')"></span>
                        </span>
                    </span>
                </button>
                <div class="flex items-center gap-3 whitespace-nowrap">
                    <a x-show="d.alterado" x-cloak :href="d.alteracoes_url"
                       class="text-red-700 hover:underline text-sm font-medium">⚠ Ver alterações</a>
                    <a :href="d.detalhar_url" class="text-accent-700 hover:underline text-sm font-medium">Detalhar →</a>
                </div>
            </div>

            <div x-show="aberto(d.id)" x-cloak x-transition.origin.top class="mt-3 border-t pt-3 divide-y">
                <template x-for="(it, i) in d.itens" :key="i">
                    <div class="flex items-center justify-between py-2 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-gray-800" x-text="it.nome_item"></span>
                            <span class="text-xs text-gray-500"
                                  x-text="[it.cod_mmv || null, it.quantidade != null ? (it.quantidade + ' ' + (it.unidade || '')) : null].filter(Boolean).join(' · ')"></span>
                        </div>
                        <span x-show="it.status" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                              :style="it.status ? `background:${it.status.cor_hex}1a;color:${it.status.cor_hex};border:1px solid ${it.status.cor_hex}40` : ''"
                              x-text="it.status?.nome"></span>
                        <span x-show="!it.status" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">—</span>
                    </div>
                </template>
            </div>
        </div>
    </template>

    <div x-show="!items.length && !loading" x-cloak class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
        <p class="py-6 text-center text-gray-400"
           x-text="filtrando ? 'Nenhum item encontrado para os filtros aplicados.' : 'Nenhum item alocado. Aloque uma demanda no Controle de Demandas.'"></p>
    </div>
</div>
@endsection
