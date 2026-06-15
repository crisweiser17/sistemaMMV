@extends('layouts.app')
@section('title', 'Engenharia · ' . $numeroReferencia)

@section('content')
@php
$itemsJson = $headers->map(fn ($h) => [
    'id' => $h->id,
    'nome_item' => $h->nome_item,
    'status' => $h->status?->nome,
    'cod_mmv' => $h->itemCotacao?->cod_mmv,
    'ni' => $h->itemCotacao?->ni,
    'quantidade' => $h->itemCotacao?->quantidade,
    'unidade' => $h->itemCotacao?->unidade?->sigla,
    'material_cliente' => $h->itemCotacao?->material_cliente,
    'descricao_cliente' => $h->itemCotacao?->descricao_cliente,
    'observacoes' => $h->itemCotacao?->observacoes,
])->values();
@endphp

<script>
    // Contexto da COTACAO/DEMANDA exposto ao Alpine. URLs sao funcoes do header (item) ativo.
    window.ENG = {
        csrf: '{{ csrf_token() }}',
        items: @json($itemsJson),
        urls: {
            linhas: (h) => `/engenharia/${h}/linhas`,
            add: (h) => `/engenharia/${h}/linha`,
            upd: (h, l) => `/engenharia/${h}/linha/${l}`,
            del: (h, l) => `/engenharia/${h}/linha/${l}`,
            gantt: (h) => `/engenharia/${h}/gantt`,
            arquivo: (h, l) => `/engenharia/${h}/linha/${l}/arquivo`,
            finalizar: (h) => `/engenharia/${h}/finalizar`,
        }
    };
</script>

<div x-data="{
        headerAtivo: window.ENG.items[0]?.id ?? null,
        get itemAtivo() { return window.ENG.items.find(i => i.id === this.headerAtivo) || {}; }
     }">

    {{-- Cabecalho da cotacao --}}
    <x-card class="mb-4">
        <div class="flex items-start justify-between">
            <div>
                <div class="text-lg font-semibold text-gray-800">{{ $numeroReferencia }}</div>
                <div class="text-sm text-gray-500 mt-1">Cliente: {{ $cliente?->nome ?? '—' }} · {{ $headers->count() }} {{ $headers->count() === 1 ? 'item' : 'itens' }}</div>
            </div>
            {{-- Acoes agrupadas por intencao: visualizar (esquerda) | gerar + acao principal (direita) --}}
            <div class="flex flex-wrap items-center gap-2">
                {{-- Grupo: visualizacao (secundarias) --}}
                <x-button variant="secondary" @click="$dispatch('abrir-gantt', headerAtivo)">📊 Gantt</x-button>
                {{-- Output e por DEMANDA (PI agrupa todos os itens), nao depende do item ativo --}}
                <x-button variant="secondary" :href="route('output.preview', $demanda)">👁 Preview PI</x-button>
                <x-button variant="secondary" :href="route('output.historico', $demanda)">📄 PDFs</x-button>
                @can('editar', 'engenharia')
                    {{-- Separador visual entre visualizar e as acoes que alteram estado --}}
                    <span class="hidden sm:block w-px h-6 bg-gray-200 mx-1"></span>
                    <form method="POST" action="{{ route('output.gerar', $demanda) }}">@csrf
                        <x-button type="submit" variant="secondary">Gerar PDF</x-button>
                    </form>
                    {{-- Acao principal da tela: unica em laranja, destacada a direita --}}
                    <form method="POST" :action="window.ENG.urls.finalizar(headerAtivo)" onsubmit="return confirm('Finalizar este item?')">
                        <input type="hidden" name="_token" :value="window.ENG.csrf">
                        <input type="hidden" name="_method" value="PUT">
                        <x-button type="submit" variant="primary">✓ Finalizar Item</x-button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Seletor de item (abas) --}}
        <div class="mt-4 flex flex-wrap gap-2 border-t pt-3">
            <template x-for="i in window.ENG.items" :key="i.id">
                <button @click="headerAtivo = i.id"
                        class="px-3 py-1.5 rounded-md text-sm border transition"
                        :class="headerAtivo === i.id ? 'bg-mmv-600 text-white border-mmv-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'">
                    <span x-text="i.nome_item || 'Item'"></span>
                    <span class="ml-1 text-xs opacity-75" x-show="i.status" x-text="'· ' + (i.status || '')"></span>
                </button>
            </template>
        </div>

        {{-- Dados do item ativo --}}
        <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-2 text-sm">
            <div><div class="text-gray-500">Cód. MMV</div><div x-text="itemAtivo.cod_mmv || '—'"></div></div>
            <div><div class="text-gray-500">NI</div><div x-text="itemAtivo.ni || '—'"></div></div>
            <div><div class="text-gray-500">Quantidade</div><div x-text="(itemAtivo.quantidade ?? '—') + ' ' + (itemAtivo.unidade || '')"></div></div>
            <div><div class="text-gray-500">Material do cliente</div><div x-text="itemAtivo.material_cliente || '—'"></div></div>
            <div class="col-span-2"><div class="text-gray-500">Descrição do cliente</div><div x-text="itemAtivo.descricao_cliente || '—'"></div></div>
            <div class="col-span-2 md:col-span-4"><div class="text-gray-500">Observações</div><div x-text="itemAtivo.observacoes || '—'"></div></div>
        </div>
    </x-card>

    {{-- Lista de linhas do item ativo (re-montada ao trocar item: destroy=>leaveChannel, init=>load+subscribe) --}}
    <template x-for="hid in [headerAtivo]" :key="hid">
        <x-card class="mb-4"
                x-data="Object.assign(
                    liveResource({ url: window.ENG.urls.linhas(hid), channel: 'engenharia.'+hid, events: ['.engenharia.atualizada'] }),
                    {
                        async remover(id) {
                            if (!confirm('Remover linha?')) return;
                            await window.mmvFetch(window.ENG.urls.del(hid, id), { method: 'DELETE' });
                            window.mmvToast('Linha removida.');
                        },
                        async enviarArquivo(id, ev) {
                            const file = ev.target.files[0];
                            if (!file) return;
                            const fd = new FormData();
                            fd.append('arquivo', file);
                            try {
                                await window.mmvFetch(window.ENG.urls.arquivo(hid, id), { method: 'POST', body: fd });
                                window.mmvToast('Arquivo anexado.');
                                this.load();
                            } catch (e) { window.mmvToast('Falha ao anexar arquivo.', 'error'); }
                            ev.target.value = '';
                        },
                        async removerArquivo(id) {
                            if (!confirm('Remover arquivo desta linha?')) return;
                            try {
                                await window.mmvFetch(window.ENG.urls.arquivo(hid, id), { method: 'DELETE' });
                                window.mmvToast('Arquivo removido.');
                                this.load();
                            } catch (e) { window.mmvToast('Falha ao remover arquivo.', 'error'); }
                        }
                    }
                )"
                @recarregar-linhas.window="load()">
            <x-slot:title>Linhas de Detalhamento<span class="ml-1 font-normal text-accent-700" x-show="itemAtivo.nome_item" x-text="'— ' + (itemAtivo.nome_item || '')"></span></x-slot:title>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-gray-500 border-b">
                        <th class="py-2 pr-3 w-10">#</th><th class="py-2 pr-3">Cód. MMV</th><th class="py-2 pr-3">Descrição</th>
                        <th class="py-2 pr-3">Componente</th><th class="py-2 pr-3">Material</th><th class="py-2 pr-3">M.O.</th>
                        <th class="py-2 pr-3">Qtd</th><th class="py-2 pr-3">Dias úteis</th><th class="py-2 pr-3">Fase</th><th class="py-2 pr-3">Deps</th>
                        <th class="py-2 pr-3">Arquivo</th>
                        @can('editar', 'engenharia')<th class="py-2 text-right">Ações</th>@endcan
                    </tr></thead>
                    <tbody class="divide-y">
                        <template x-for="l in items" :key="l.id">
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 pr-3" x-text="l.numero_linha"></td>
                                <td class="py-2 pr-3" x-text="l.cod_mmv"></td>
                                <td class="py-2 pr-3" x-text="l.descricao"></td>
                                <td class="py-2 pr-3 capitalize" x-text="(l.tipo_componente || '').replace('_',' ')"></td>
                                <td class="py-2 pr-3" x-text="l.material ?? '—'"></td>
                                <td class="py-2 pr-3" x-text="l.mao_de_obra ?? '—'"></td>
                                <td class="py-2 pr-3" x-text="l.quantidade"></td>
                                <td class="py-2 pr-3" x-text="l.duracao_dias ?? '—'"></td>
                                <td class="py-2 pr-3" x-text="l.fase ?? '—'"></td>
                                <td class="py-2 pr-3" x-text="(l.dependencias || []).join(', ')"></td>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    <template x-if="l.arquivo_nome">
                                        <span class="inline-flex items-center gap-2">
                                            <a :href="window.ENG.urls.arquivo(hid, l.id)" target="_blank" rel="noopener" class="text-accent-700 hover:underline" x-text="'📎 ' + l.arquivo_nome"></a>
                                            @can('editar', 'engenharia')<button @click="removerArquivo(l.id)" class="text-red-600 hover:underline text-xs" title="Remover arquivo">✕</button>@endcan
                                        </span>
                                    </template>
                                    <template x-if="!l.arquivo_nome">
                                        @can('editar', 'engenharia')
                                            <label class="text-accent-700 hover:underline cursor-pointer text-xs">+ Anexar
                                                <input type="file" class="hidden" @change="enviarArquivo(l.id, $event)">
                                            </label>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endcan
                                    </template>
                                </td>
                                @can('editar', 'engenharia')
                                    <td class="py-2 text-right whitespace-nowrap">
                                        <button @click="$dispatch('editar-linha', l)" class="text-accent-700 hover:underline">Editar</button>
                                        <button @click="remover(l.id)" class="text-red-600 hover:underline ml-2">Excluir</button>
                                    </td>
                                @endcan
                            </tr>
                        </template>
                        <tr x-show="!items.length && !loading"><td colspan="12" class="py-6 text-center text-gray-400">Nenhuma linha. Adicione abaixo.</td></tr>
                    </tbody>
                </table>
            </div>
        </x-card>
    </template>

    @can('editar', 'engenharia')
    {{-- Formulario de linha (add/edit) com dropdowns encadeados (dependentSelects) --}}
    <x-card
            x-data="Object.assign(dependentSelects({}), {
                editId: null,
                campos: { cod_mmv: '', descricao: '', mao_de_obra: '', quantidade: '', duracao_dias: 2, fase: '', dependencias: '' },
                resetar() {
                    this.editId = null;
                    this.campos = { cod_mmv: '', descricao: '', mao_de_obra: '', quantidade: '', duracao_dias: 2, fase: '', dependencias: '' };
                    this.tipo_componente = this.categoria_componente_id = this.tipo_componente_id = this.material_id = '';
                    this.categorias = this.tipos = this.materiais = [];
                },
                async preencher(l) {
                    this.resetar();
                    this.editId = l.id;
                    this.campos.cod_mmv = l.cod_mmv || ''; this.campos.descricao = l.descricao || '';
                    this.campos.mao_de_obra = l.mao_de_obra || ''; this.campos.quantidade = l.quantidade || '';
                    this.campos.duracao_dias = l.duracao_dias ?? 2;
                    this.campos.fase = l.fase || ''; this.campos.dependencias = (l.dependencias || []).join(',');
                    this.tipo_componente = l.tipo_componente || '';
                    if (this.tipo_componente) await this.carregarCategorias(false);
                    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                },
                payload() {
                    return {
                        cod_mmv: this.campos.cod_mmv, descricao: this.campos.descricao,
                        tipo_componente: this.tipo_componente || null,
                        categoria_componente_id: this.categoria_componente_id || null,
                        tipo_componente_id: this.tipo_componente_id || null,
                        material_id: this.material_id || null,
                        mao_de_obra: this.campos.mao_de_obra, quantidade: this.campos.quantidade || 0,
                        duracao_dias: this.campos.duracao_dias || 1,
                        fase: this.campos.fase, dependencias: this.campos.dependencias,
                    };
                },
                async salvar(hid) {
                    try {
                        if (this.editId) {
                            await window.mmvFetch(window.ENG.urls.upd(hid, this.editId), { method: 'PUT', body: this.payload() });
                        } else {
                            await window.mmvFetch(window.ENG.urls.add(hid), { method: 'POST', body: this.payload() });
                        }
                        window.mmvToast('Linha salva.');
                        this.resetar();
                        this.$dispatch('recarregar-linhas');
                    } catch (e) { window.mmvToast('Falha ao salvar linha.', 'error'); }
                }
            })"
            @editar-linha.window="preencher($event.detail)"
            x-effect="headerAtivo; resetar()">
        <x-slot:title><span x-text="editId ? 'Editar Linha' : 'Adicionar Linha'"></span><span class="ml-1 font-normal text-accent-700" x-show="itemAtivo.nome_item" x-text="'— ' + (itemAtivo.nome_item || '')"></span></x-slot:title>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Cód. MMV</span>
                <input x-model="campos.cod_mmv" class="w-full rounded-md border-gray-300 text-sm"></label>
            <label class="block md:col-span-3"><span class="block text-sm font-medium text-gray-700 mb-1">Descrição</span>
                <input x-model="campos.descricao" class="w-full rounded-md border-gray-300 text-sm"></label>

            {{-- Dropdowns encadeados --}}
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Componente</span>
                <select x-model="tipo_componente" @change="onComponenteChange()" class="w-full rounded-md border-gray-300 text-sm">
                    <option value="">—</option><option value="materia_prima">Matéria-prima</option>
                    <option value="servico">Serviço</option><option value="comercial">Comercial</option>
                </select></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Categoria</span>
                <select x-model="categoria_componente_id" @change="onCategoriaChange()" class="w-full rounded-md border-gray-300 text-sm" :disabled="!categorias.length">
                    <option value="">—</option>
                    <template x-for="c in categorias" :key="c.id"><option :value="c.id" x-text="c.nome"></option></template>
                </select></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Tipo</span>
                <select x-model="tipo_componente_id" @change="onTipoChange()" class="w-full rounded-md border-gray-300 text-sm" :disabled="!tipos.length">
                    <option value="">—</option>
                    <template x-for="t in tipos" :key="t.id"><option :value="t.id" x-text="t.nome"></option></template>
                </select></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Material</span>
                <select x-model="material_id" class="w-full rounded-md border-gray-300 text-sm" :disabled="!materiais.length">
                    <option value="">—</option>
                    <template x-for="m in materiais" :key="m.id"><option :value="m.id" x-text="m.descricao"></option></template>
                </select></label>

            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Mão de obra</span>
                <input x-model="campos.mao_de_obra" class="w-full rounded-md border-gray-300 text-sm"></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Quantidade</span>
                <input x-model="campos.quantidade" type="number" step="0.001" class="w-full rounded-md border-gray-300 text-sm"></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Duração (dias úteis)</span>
                <input x-model="campos.duracao_dias" type="number" min="1" step="1" class="w-full rounded-md border-gray-300 text-sm"></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Fase</span>
                <input x-model="campos.fase" class="w-full rounded-md border-gray-300 text-sm"></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Dependências (nº linhas, ex.: 2,3)</span>
                <input x-model="campos.dependencias" class="w-full rounded-md border-gray-300 text-sm"></label>
        </div>

        <div class="flex gap-2 mt-4">
            <x-button @click="salvar(headerAtivo)"><span x-text="editId ? 'Atualizar linha' : 'Adicionar linha'"></span></x-button>
            <x-button variant="secondary" @click="resetar()" x-show="editId">Cancelar edição</x-button>
        </div>
    </x-card>
    @endcan

    {{-- Modal do Gantt — render proprio em HTML/CSS (sem dependencia externa) --}}
    <div x-data="{
            aberto: false, tasks: [], dias: [], min: 0, dayWidth: 40, labelWidth: 240,
            async abrir(hid) {
                this.aberto = true;
                try { this.tasks = await window.mmvFetch(window.ENG.urls.gantt(hid)); }
                catch (e) { this.tasks = []; window.mmvToast('Falha ao carregar o Gantt.', 'error'); }
                this.montar();
            },
            _d(s) { return new Date(s + 'T00:00:00').getTime(); },
            montar() {
                if (!this.tasks.length) { this.dias = []; return; }
                this.min = Math.min(...this.tasks.map(t => this._d(t.start)));
                const max = Math.max(...this.tasks.map(t => this._d(t.end)));
                const dias = [];
                for (let d = this.min; d <= max; d += 86400000) dias.push(new Date(d));
                this.dias = dias;
            },
            offset(t) { return (this._d(t.start) - this.min) / 86400000; },
            duracao(t) { return Math.max(1, (this._d(t.end) - this._d(t.start)) / 86400000); },
            fmt(d) { return ('0'+d.getDate()).slice(-2) + '/' + ('0'+(d.getMonth()+1)).slice(-2); },
            depLabel(t) { return t.dependencies ? ('depende de L' + String(t.dependencies).split(',').join(', L')) : ''; }
         }"
         @abrir-gantt.window="abrir($event.detail)"
         x-show="aberto" style="display:none" class="fixed inset-0 z-40 overflow-auto">
        <div class="flex items-start justify-center min-h-screen p-4">
            <div x-show="aberto" x-transition.opacity @click="aberto=false" class="fixed inset-0 bg-black/50"></div>
            <div x-show="aberto" x-transition class="relative bg-white rounded-lg shadow-xl w-full max-w-5xl mt-10 p-5">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Gantt — sequência das etapas</h3>
                    <button @click="aberto=false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>

                <p x-show="!tasks.length" class="text-sm text-gray-400 py-6">Sem linhas para exibir. Adicione linhas ao item para montar o cronograma.</p>

                <div x-show="tasks.length" class="overflow-x-auto border rounded">
                    <div :style="`min-width:${labelWidth + dias.length * dayWidth}px`">
                        <div class="flex bg-gray-50 border-b sticky top-0">
                            <div class="shrink-0 px-3 py-2 text-xs font-medium text-gray-500" :style="`width:${labelWidth}px`">Etapa</div>
                            <template x-for="(d, i) in dias" :key="i">
                                <div class="shrink-0 text-center text-[10px] text-gray-500 py-2 border-l" :style="`width:${dayWidth}px`" x-text="fmt(d)"></div>
                            </template>
                        </div>

                        <template x-for="t in tasks" :key="t.id">
                            <div class="flex items-center border-b hover:bg-gray-50">
                                <div class="shrink-0 px-3 py-2" :style="`width:${labelWidth}px`">
                                    <div class="text-xs font-medium text-gray-800 truncate" x-text="t.name"></div>
                                    <div class="text-[10px] text-gray-400" x-show="t.dependencies" x-text="depLabel(t)"></div>
                                </div>
                                <div class="relative" :style="`width:${dias.length * dayWidth}px; height:40px`">
                                    <template x-for="(d, i) in dias" :key="'g'+i">
                                        <div class="absolute top-0 bottom-0 border-l border-gray-100" :style="`left:${i * dayWidth}px`"></div>
                                    </template>
                                    <div class="absolute rounded shadow-sm flex items-center px-2 text-[10px] text-[#1E1E1E] font-medium overflow-hidden whitespace-nowrap"
                                         :style="`left:${offset(t) * dayWidth}px; width:${duracao(t) * dayWidth}px; top:8px; height:24px; background:${t.progress >= 100 ? '#10b981' : '#EF8332'}`"
                                         :title="t.name"
                                         x-text="(t.progress >= 100 ? '✓ ' : '') + duracao(t) + 'd'"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="flex gap-4 mt-3 text-xs text-gray-500">
                    <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded" style="background:#EF8332"></span> Em andamento</span>
                    <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded" style="background:#10b981"></span> Finalizada</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
