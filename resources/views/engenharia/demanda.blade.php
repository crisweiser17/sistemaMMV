@extends('layouts.app')
@section('title', 'Engenharia · ' . $numeroReferencia)

@section('content')
@php
// dadosItemOrigem() resolve o item de origem (PI ou cotacao) num shape unico.
$itemsJson = $headers->map(fn ($h) => array_merge([
    'id' => $h->id,
    'nome_item' => $h->nome_item,
    'status' => $h->status?->nome,
], $h->dadosItemOrigem()))->values();
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
            estruturas: (h) => `/engenharia/${h}/estruturas`,
            copiarEstrutura: (h) => `/engenharia/${h}/copiar-estrutura`,
        },
        // Espelha as regras do servidor (App\Services\AnexoService) para dar
        // feedback imediato; o servidor continua sendo a autoridade.
        anexo: {
            extensoes: @json(\App\Services\AnexoService::EXTENSOES),
            maxMb: {{ \App\Services\AnexoService::limiteMb() }},
            accept: '{{ \App\Services\AnexoService::accept() }}',
            legiveis: '{{ \App\Services\AnexoService::extensoesLegiveis() }}',
        }
    };

    // Devolve a mensagem de erro, ou null quando o arquivo esta ok.
    window.ENG.validarAnexo = function (file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!window.ENG.anexo.extensoes.includes(ext)) {
            return `Extensão .${ext} não aceita. Envie: ${window.ENG.anexo.legiveis}.`;
        }
        if (file.size > window.ENG.anexo.maxMb * 1024 * 1024) {
            const mb = (file.size / 1024 / 1024).toFixed(1);
            return `Arquivo de ${mb} MB excede o limite de ${window.ENG.anexo.maxMb} MB.`;
        }
        return null;
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
                <div class="text-sm text-gray-500 mt-1">Cliente: {{ $clienteRotulo ?? '—' }} · {{ $headers->count() }} {{ $headers->count() === 1 ? 'item' : 'itens' }}</div>
            </div>
            {{-- Acoes agrupadas por intencao: visualizar (esquerda) | gerar + acao principal (direita) --}}
            <div class="flex flex-wrap items-center gap-2">
                {{-- Grupo: visualizacao (secundarias) --}}
                <x-button variant="secondary" @click="$dispatch('abrir-gantt', headerAtivo)">📊 Gantt</x-button>
                {{-- Output e por DEMANDA (PI agrupa todos os itens), nao depende do item ativo --}}
                <x-button variant="secondary" :href="route('output.preview', $demanda)">👁 Preview PI</x-button>
                <x-button variant="secondary" :href="route('output.historico', $demanda)">📄 PDFs</x-button>
                {{-- Aparece so quando o processo mudou depois do ultimo PDF. --}}
                @if ($alteracoes->ativo())
                    <x-button variant="secondary" :href="route('output.alteracoes', $demanda)">⚠ Alterações ({{ $alteracoes->total() }})</x-button>
                @endif
                @can('editar', 'engenharia')
                    {{-- Separador visual entre visualizar e as acoes que alteram estado --}}
                    <span class="hidden sm:block w-px h-6 bg-gray-200 mx-1"></span>
                    <x-button variant="secondary" @click="$dispatch('abrir-copia-estrutura', headerAtivo)">⧉ Copiar estrutura de…</x-button>
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
            <div><div class="text-gray-500">NI / OI</div><div x-text="itemAtivo.ni || '—'"></div></div>
            {{-- NF efetiva do item (propria do item ou herdada do PI); cotacao nao tem NF por item. --}}
            <div><div class="text-gray-500">NF</div><div x-text="itemAtivo.nf || '—'"></div></div>
            <div><div class="text-gray-500">Quantidade</div><div x-text="(itemAtivo.quantidade ?? '—') + ' ' + (itemAtivo.unidade || '')"></div></div>
            <div><div class="text-gray-500">Material do cliente</div><div x-text="itemAtivo.material_cliente || '—'"></div></div>
            <div class="col-span-2"><div class="text-gray-500">Descrição</div><div x-text="itemAtivo.descricao || '—'"></div></div>
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
                            // Valida no cliente antes de subir: evita esperar 50 MB para receber 422.
                            const erroLocal = window.ENG.validarAnexo(file);
                            if (erroLocal) { window.mmvToast(erroLocal, 'error'); ev.target.value = ''; return; }

                            const fd = new FormData();
                            fd.append('arquivo', file);
                            try {
                                await window.mmvFetch(window.ENG.urls.arquivo(hid, id), { method: 'POST', body: fd });
                                window.mmvToast('Arquivo anexado.');
                                this.load();
                            } catch (e) { window.mmvToastErro(e, 'Falha ao anexar arquivo.'); }
                            ev.target.value = '';
                        },
                        async removerArquivo(id) {
                            if (!confirm('Remover arquivo desta linha?')) return;
                            try {
                                await window.mmvFetch(window.ENG.urls.arquivo(hid, id), { method: 'DELETE' });
                                window.mmvToast('Arquivo removido.');
                                this.load();
                            } catch (e) { window.mmvToastErro(e, 'Falha ao remover arquivo.'); }
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
                                <td class="py-2 pr-3">
                                    <div x-text="l.descricao"></div>
                                    {{-- Observacao livre como segunda linha da celula: a tabela ja tem 12 colunas,
                                         uma 13a estouraria a largura. Fica sempre visivel (nao depende de hover),
                                         colada na descricao que ela qualifica — igual ao que sai no PDF. --}}
                                    <div x-show="l.observacao"
                                         class="mt-1 pl-2 border-l-2 border-accent-500 text-xs text-amber-800"
                                         x-text="'Obs.: ' + (l.observacao || '')"></div>
                                </td>
                                <td class="py-2 pr-3 capitalize" x-text="(l.tipo_componente || '').replace('_',' ')"></td>
                                {{-- Especificacao completa do Cadastro (categoria, dimensoes, norma); title para o texto inteiro. --}}
                                <td class="py-2 pr-3" :title="l.material" x-text="l.material ?? '—'"></td>
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
                                            <label class="text-accent-700 hover:underline cursor-pointer text-xs"
                                                   title="Formatos: {{ \App\Services\AnexoService::extensoesLegiveis() }} · até {{ \App\Services\AnexoService::limiteMb() }} MB">+ Anexar
                                                <input type="file" class="hidden"
                                                       accept="{{ \App\Services\AnexoService::accept() }}"
                                                       @change="enviarArquivo(l.id, $event)">
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
                campos: { cod_mmv: '', descricao: '', mao_de_obra: '', quantidade: '', duracao_dias: 2, observacao: '', fase: '', dependencias: '' },
                resetar() {
                    this.editId = null;
                    this.campos = { cod_mmv: '', descricao: '', mao_de_obra: '', quantidade: '', duracao_dias: 2, observacao: '', fase: '', dependencias: '' };
                    this.tipo_componente = this.categoria_componente_id = this.tipo_componente_id = this.material_id = '';
                    this.categorias = this.tipos = this.materiais = [];
                },
                async preencher(l) {
                    this.resetar();
                    this.editId = l.id;
                    this.campos.cod_mmv = l.cod_mmv || ''; this.campos.descricao = l.descricao || '';
                    this.campos.mao_de_obra = l.mao_de_obra || ''; this.campos.quantidade = l.quantidade || '';
                    this.campos.duracao_dias = l.duracao_dias ?? 2;
                    this.campos.observacao = l.observacao || '';
                    this.campos.fase = l.fase || ''; this.campos.dependencias = (l.dependencias || []).join(',');
                    // Remonta a cadeia componente -> categoria -> tipo -> material: cada nivel so pode
                    // ser selecionado depois que as opcoes do nivel anterior chegarem (reset=false preserva a selecao).
                    this.tipo_componente = l.tipo_componente || '';
                    if (this.tipo_componente) await this.carregarCategorias(false);
                    this.categoria_componente_id = l.categoria_componente_id || '';
                    if (this.categoria_componente_id) await this.carregarTipos(false);
                    this.tipo_componente_id = l.tipo_componente_id || '';
                    if (this.tipo_componente_id) await this.carregarMateriais(false);
                    this.material_id = l.material_id || '';
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
                        observacao: this.campos.observacao,
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
            {{-- Cada nivel fica desabilitado ate o anterior ser escolhido; a dica explica o porque de estar vazio. --}}
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Categoria</span>
                <select x-model="categoria_componente_id" @change="onCategoriaChange()" class="w-full rounded-md border-gray-300 text-sm" :disabled="!categorias.length">
                    <option value="">—</option>
                    <template x-for="c in categorias" :key="c.id"><option :value="c.id" x-text="c.nome"></option></template>
                </select>
                <span class="block text-xs text-gray-400 mt-1" x-show="!categorias.length"
                      x-text="tipo_componente ? 'Nenhuma categoria cadastrada para este componente.' : 'Escolha o componente primeiro.'"></span></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Tipo</span>
                <select x-model="tipo_componente_id" @change="onTipoChange()" class="w-full rounded-md border-gray-300 text-sm" :disabled="!tipos.length">
                    <option value="">—</option>
                    <template x-for="t in tipos" :key="t.id"><option :value="t.id" x-text="t.nome"></option></template>
                </select>
                <span class="block text-xs text-gray-400 mt-1" x-show="!tipos.length"
                      x-text="categoria_componente_id ? 'Nenhum tipo cadastrado para esta categoria.' : 'Escolha a categoria primeiro.'"></span></label>
            <label class="block"><span class="block text-sm font-medium text-gray-700 mb-1">Material</span>
                <select x-model="material_id" class="w-full rounded-md border-gray-300 text-sm" :disabled="!materiais.length">
                    <option value="">—</option>
                    {{-- Rotulo completo (categoria, dimensoes e norma) montado no model Material. --}}
                    <template x-for="m in materiais" :key="m.id"><option :value="m.id" x-text="m.especificacao"></option></template>
                </select>
                <span class="block text-xs text-gray-400 mt-1" x-show="!materiais.length"
                      x-text="tipo_componente_id ? 'Nenhum material cadastrado para este tipo.' : 'Escolha o tipo primeiro.'"></span></label>

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

            {{-- Textarea (nao input): e frase inteira em texto livre, e vai impressa no PDF. --}}
            <label class="block md:col-span-4"><span class="block text-sm font-medium text-gray-700 mb-1">Observação</span>
                <textarea x-model="campos.observacao" rows="2"
                          placeholder="Ex.: fazer aproveitamento junto com a chapa X"
                          class="w-full rounded-md border-gray-300 text-sm"></textarea>
                <span class="block text-xs text-gray-400 mt-1">Sai impressa na folha de processo (PDF), visível para comprador e produção.</span></label>
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

    @can('editar', 'engenharia')
    {{-- Modal "Copiar estrutura de..." — reaproveita o detalhamento de um item ja concluido --}}
    <div x-data="{
            aberto: false, hid: null, busca: '', itens: [], origemId: null,
            carregando: false, copiando: false, linhasAtuais: 0, timer: null,
            async abrir(hid) {
                this.aberto = true; this.hid = hid; this.busca = ''; this.origemId = null; this.itens = [];
                // Quantas linhas o item ja tem decide se e preciso perguntar
                // acrescentar x substituir antes de copiar.
                try {
                    const atual = await window.mmvFetch(window.ENG.urls.linhas(hid));
                    this.linhasAtuais = (atual.data || []).length;
                } catch (e) { this.linhasAtuais = 0; }
                this.buscar();
            },
            fechar() { clearTimeout(this.timer); this.aberto = false; },
            aoDigitar() { clearTimeout(this.timer); this.timer = setTimeout(() => this.buscar(), 300); },
            async buscar() {
                this.carregando = true;
                try {
                    const url = window.ENG.urls.estruturas(this.hid) + '?busca=' + encodeURIComponent(this.busca);
                    this.itens = (await window.mmvFetch(url)).data || [];
                    // A escolha anterior pode ter saido do resultado: nao copiar as cegas.
                    if (!this.itens.some(i => i.id === this.origemId)) this.origemId = null;
                } catch (e) { this.itens = []; window.mmvToastErro(e, 'Falha ao buscar estruturas.'); }
                this.carregando = false;
            },
            async copiar(modo) {
                if (!this.origemId || this.copiando) return;
                if (modo === '{{ \App\Services\EngenhariaService::MODO_SUBSTITUIR }}'
                    && !confirm('Substituir as ' + this.linhasAtuais + ' linhas atuais pela estrutura escolhida?')) return;
                this.copiando = true;
                try {
                    const r = await window.mmvFetch(window.ENG.urls.copiarEstrutura(this.hid), {
                        method: 'POST', body: { origem_id: this.origemId, modo }
                    });
                    window.mmvToast(r.linhas + (r.linhas === 1 ? ' linha copiada.' : ' linhas copiadas.'));
                    this.fechar();
                    this.$dispatch('recarregar-linhas');
                } catch (e) { window.mmvToastErro(e, 'Falha ao copiar a estrutura.'); }
                this.copiando = false;
            }
         }"
         @abrir-copia-estrutura.window="abrir($event.detail)"
         @keydown.escape.window="fechar()"
         x-show="aberto" style="display:none" class="fixed inset-0 z-40 overflow-auto">
        <div class="flex items-start justify-center min-h-screen p-4">
            <div x-show="aberto" x-transition.opacity @click="fechar()" class="fixed inset-0 bg-black/50"></div>
            <div x-show="aberto" x-transition class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl mt-10 p-5">
                <div class="flex justify-between items-center mb-1">
                    <h3 class="text-lg font-semibold">Copiar estrutura de outro item</h3>
                    <button @click="fechar()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
                <p class="text-sm text-gray-500 mb-4">
                    Traz as linhas de detalhamento e as dependências de um item já finalizado. Anexos e status das linhas não vêm junto.
                </p>

                <input x-model="busca" @input="aoDigitar()" @keydown.enter.prevent="buscar()"
                       placeholder="Buscar por cód. MMV, NI, descrição ou nº do pedido…"
                       class="w-full rounded-md border-gray-300 text-sm mb-3">

                <div class="border rounded divide-y max-h-80 overflow-auto">
                    <p x-show="carregando" class="p-4 text-sm text-gray-400">Buscando…</p>
                    <p x-show="!carregando && !itens.length" class="p-4 text-sm text-gray-400">
                        Nenhum item concluído com linhas de detalhamento para esta busca.
                    </p>
                    <template x-for="i in itens" :key="i.id">
                        <label class="flex items-start gap-3 p-3 cursor-pointer hover:bg-gray-50"
                               :class="origemId === i.id ? 'bg-accent-50' : ''">
                            <input type="radio" :value="i.id" x-model.number="origemId" class="mt-1 text-mmv-600">
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm font-medium text-gray-800 truncate">
                                    <span x-text="i.numero_referencia || '—'"></span> ·
                                    <span x-text="i.nome_item || 'Item'"></span>
                                </span>
                                <span class="block text-xs text-gray-500 truncate">
                                    <span x-show="i.cod_mmv" x-text="'Cód. MMV ' + (i.cod_mmv || '') + ' · '"></span>
                                    <span x-show="i.ni" x-text="'NI ' + (i.ni || '') + ' · '"></span>
                                    <span x-text="i.cliente || '—'"></span>
                                    <span x-show="i.data" x-text="' · ' + (i.data || '')"></span>
                                </span>
                            </span>
                            <span class="shrink-0 text-xs text-accent-700 whitespace-nowrap"
                                  x-text="i.linhas + (i.linhas === 1 ? ' linha' : ' linhas')"></span>
                        </label>
                    </template>
                </div>

                {{-- Item ja detalhado: o usuario decide antes de copiar, nunca por padrao. --}}
                <p x-show="linhasAtuais > 0" class="mt-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                    Este item já tem <span x-text="linhasAtuais"></span>
                    <span x-text="linhasAtuais === 1 ? 'linha' : 'linhas'"></span>. Escolha o que fazer com elas.
                </p>

                <div class="flex justify-end gap-2 mt-4">
                    <x-button variant="secondary" @click="fechar()">Cancelar</x-button>
                    <template x-if="linhasAtuais > 0">
                        <span class="flex gap-2">
                            <x-button variant="secondary" x-bind:disabled="!origemId || copiando"
                                      @click="copiar('{{ \App\Services\EngenhariaService::MODO_SUBSTITUIR }}')">Substituir tudo</x-button>
                            <x-button x-bind:disabled="!origemId || copiando"
                                      @click="copiar('{{ \App\Services\EngenhariaService::MODO_ACRESCENTAR }}')">Acrescentar ao final</x-button>
                        </span>
                    </template>
                    <template x-if="linhasAtuais === 0">
                        <x-button x-bind:disabled="!origemId || copiando"
                                  @click="copiar('{{ \App\Services\EngenhariaService::MODO_ACRESCENTAR }}')">Copiar estrutura</x-button>
                    </template>
                </div>
            </div>
        </div>
    </div>
    @endcan
</div>
@endsection
