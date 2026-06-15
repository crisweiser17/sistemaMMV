@extends('layouts.app')
@section('title', $cotacao->exists ? 'Editar Cotação' : 'Nova Cotação')

@php
    $itensIniciais = $cotacao->exists
        ? $cotacao->itens->map(fn ($i) => $i->only(['id','numero_item','cod_mmv','ni','descricao','quantidade','unidade_id','material_cliente','descricao_cliente','observacoes']))->values()
        : [];
    $template = ['id' => null, 'cod_mmv' => '', 'ni' => '', 'descricao' => '', 'quantidade' => '', 'unidade_id' => '', 'material_cliente' => '', 'descricao_cliente' => '', 'observacoes' => ''];
@endphp

@section('content')
<form method="POST" action="{{ $cotacao->exists ? route('cotacao.update', $cotacao) : route('cotacao.store') }}"
      x-data="{ ...itemsRepeater(@js($itensIniciais), @js($template)), ...niLookup() }"
      @submit="if (!validar()) { $event.preventDefault(); window.mmvToast('Cada item precisa de descrição e quantidade.', 'error'); }">
    @csrf
    @if ($cotacao->exists) @method('PUT') @endif

    <x-card title="Dados da Cotação" class="mb-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <x-input label="Número" name="numero" :value="$cotacao->numero" />
            <x-input label="Número do Cliente" name="numero_cliente" :value="$cotacao->numero_cliente" />
            <x-select label="Cliente" name="cliente_id" :options="$clientes->toArray()" :value="$cotacao->cliente_id" />
            <x-select label="Escopo" name="escopo_id" :options="$escopos->toArray()" :value="$cotacao->escopo_id" />
            <x-input label="Data da Cotação" name="data_cotacao" type="date" :value="optional($cotacao->data_cotacao)->format('Y-m-d')" />
            <x-input label="Prazo de Resposta" name="prazo_resposta" type="date" :value="optional($cotacao->prazo_resposta)->format('Y-m-d')" />
            <x-input label="NF Cliente" name="nf_cliente" :value="$cotacao->nf_cliente" />
            <label class="block md:col-span-3">
                <span class="block text-sm font-medium text-gray-700 mb-1">Observações</span>
                <textarea name="observacoes" rows="2" class="w-full rounded-md border-gray-300 text-sm">{{ $cotacao->observacoes }}</textarea>
            </label>
        </div>
    </x-card>

    <x-card title="Itens" class="mb-4">
        <x-slot:actions>
            <x-button variant="secondary" @click="add()">+ Adicionar Item</x-button>
        </x-slot:actions>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b">
                    <th class="py-2 px-2 w-12">#</th><th class="py-2 px-2">Cód. MMV</th>
                    <th class="py-2 px-2">NI</th><th class="py-2 px-2">Descrição</th>
                    <th class="py-2 px-2 w-24">Qtd</th><th class="py-2 px-2 w-24">Un.</th>
                    <th class="py-2 px-2">Mat. Cliente</th><th class="py-2 px-2 w-10"></th>
                </tr></thead>
                <tbody>
                    <template x-for="(item, idx) in itens" :key="item._key">
                        <tr class="border-b">
                            <td class="py-1 px-2 text-gray-500" x-text="idx + 1"></td>
                            <td class="py-1 px-2"><input type="hidden" :name="`itens[${idx}][id]`" :value="item.id">
                                <input :name="`itens[${idx}][numero_item]`" type="hidden" :value="idx + 1">
                                <input :name="`itens[${idx}][cod_mmv]`" x-model="item.cod_mmv" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2">
                                <input :name="`itens[${idx}][ni]`" x-model="item.ni" @blur="buscar(item.ni)"
                                       class="w-full rounded border-gray-300 text-sm" placeholder="NI"></td>
                            <td class="py-1 px-2">
                                <input :name="`itens[${idx}][descricao]`" x-model="item.descricao" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2">
                                <input :name="`itens[${idx}][quantidade]`" x-model="item.quantidade" type="number" step="0.001" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2">
                                <select :name="`itens[${idx}][unidade_id]`" x-model="item.unidade_id" class="w-full rounded border-gray-300 text-sm">
                                    <option value="">—</option>
                                    @foreach ($unidades as $id => $sigla)<option value="{{ $id }}">{{ $sigla }}</option>@endforeach
                                </select></td>
                            <td class="py-1 px-2">
                                <input :name="`itens[${idx}][material_cliente]`" x-model="item.material_cliente" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2 text-center">
                                <button type="button" @click="remove(item._key)" class="text-red-500 hover:text-red-700" title="Remover">&times;</button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Modal de historico por NI (fabrica niLookup) --}}
        <x-modal x-model="aberto" maxWidth="3xl">
            <x-slot:header>Histórico da peça NI <span x-text="ni"></span></x-slot:header>
            <div class="space-y-3 text-sm">
                <template x-for="(h, i) in historico" :key="i">
                    <div class="border rounded p-3">
                        <div class="flex justify-between font-medium">
                            <span x-text="h.origem + ' ' + h.numero"></span>
                            <span class="text-gray-400" x-text="h.cliente + ' · ' + (h.data || '')"></span>
                        </div>
                        <ul class="mt-2 text-gray-600 list-disc list-inside">
                            <template x-for="(it, j) in h.itens" :key="j">
                                <li><span x-text="it.descricao"></span> — <span x-text="it.quantidade"></span></li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
            <x-slot:footer><x-button variant="secondary" @click="fechar()">Fechar</x-button></x-slot:footer>
        </x-modal>
    </x-card>

    <div class="flex gap-2">
        <x-button type="submit">Salvar Cotação</x-button>
        <x-button variant="secondary" :href="route('cotacao.index')">Cancelar</x-button>
    </div>
</form>
@endsection
