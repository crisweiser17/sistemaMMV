@extends('layouts.app')
@section('title', $liberacao->exists ? 'Editar PI' : 'Novo PI')

@php
    $itensIniciais = $liberacao->exists
        ? $liberacao->itens->map(fn ($i) => $i->only(['id','numero_item','cod_mmv','ni','descricao','quantidade','unidade_id','material_cliente','nf_cliente','prazo_entrega_item','descricao_cliente','observacoes']))->values()
        : [];
    $template = ['id' => null, 'cod_mmv' => '', 'ni' => '', 'descricao' => '', 'quantidade' => '', 'unidade_id' => '', 'material_cliente' => '', 'nf_cliente' => '', 'prazo_entrega_item' => '', 'descricao_cliente' => '', 'observacoes' => ''];
@endphp

@section('content')
<form method="POST" action="{{ $liberacao->exists ? route('liberacao.update', $liberacao) : route('liberacao.store') }}"
      x-data="Object.assign(itemsRepeater(@js($itensIniciais), @js($template)), unidadesDoCliente(), {
                  unidadeAtual: @js(old('unidade_id', $liberacao->unidade_id)),
                  {{-- NF do PI espelhada no Alpine: os itens mostram como placeholder o que herdam. --}}
                  nfPi: @js(old('nf_cliente', $liberacao->nf_cliente) ?? ''),
                  init() { return this.carregarUnidades(@js($liberacao->cliente_id)); }
              })"
      @submit="if (!validar()) { $event.preventDefault(); window.mmvToast('Cada item precisa de descrição e quantidade.', 'error'); }">
    @csrf
    @if ($liberacao->exists) @method('PUT') @endif

    <x-card title="Dados do PI" class="mb-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <x-input label="Número PI" name="numero_pi" :value="$liberacao->numero_pi" />
            <x-input label="Número PC" name="numero_pc" :value="$liberacao->numero_pc" />
            <x-select label="Cliente" name="cliente_id" :options="$clientes->toArray()" :value="$liberacao->cliente_id"
                      @change="unidadeAtual = ''; carregarUnidades($event.target.value)" />
            {{-- Unidade do cliente: as opcoes vem do lookup JSON conforme o cliente escolhido. --}}
            <label class="block">
                <span class="block text-sm font-medium text-gray-700 mb-1">Unidade</span>
                {{-- Sem disabled: cliente sem unidades precisa enviar vazio e limpar o vinculo. --}}
                <select name="unidade_id"
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-accent-500 focus:ring-accent-500 text-sm">
                    <option value="">Selecione...</option>
                    <template x-for="u in unidades" :key="u.id">
                        <option :value="u.id" :selected="String(u.id) === String(unidadeAtual)" x-text="u.nome"></option>
                    </template>
                </select>
                @error('unidade_id')<span class="block text-xs text-red-600 mt-1">{{ $message }}</span>@enderror
            </label>
            <x-select label="Escopo" name="escopo_id" :options="$escopos->toArray()" :value="$liberacao->escopo_id" />
            <x-input label="Data do Pedido" name="data_pedido" type="date" :value="optional($liberacao->data_pedido)->format('Y-m-d')" />
            {{-- NF do PI: vale para todos os itens que nao tiverem NF propria. --}}
            <x-input label="NF Cliente" name="nf_cliente" :value="$liberacao->nf_cliente" x-model="nfPi" />
            <x-input label="Data Entrega Cliente" name="data_entrega_cliente" type="date" :value="optional($liberacao->data_entrega_cliente)->format('Y-m-d')" />
            <div class="md:col-span-2 flex items-end text-sm text-gray-500">
                Prazo de entrega (dias) será calculado automaticamente pelo maior prazo entre os itens.
            </div>
            <label class="block md:col-span-3">
                <span class="block text-sm font-medium text-gray-700 mb-1">Observações</span>
                <textarea name="observacoes" rows="2" class="w-full rounded-md border-gray-300 text-sm">{{ $liberacao->observacoes }}</textarea>
            </label>
        </div>
    </x-card>

    <x-card title="Itens" class="mb-4">
        <x-slot:actions><x-button variant="secondary" @click="add()">+ Adicionar Item</x-button></x-slot:actions>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b">
                    <th class="py-2 px-2 w-12">#</th><th class="py-2 px-2">Cód. MMV</th><th class="py-2 px-2">NI</th>
                    <th class="py-2 px-2">Descrição</th><th class="py-2 px-2 w-24">Qtd</th><th class="py-2 px-2 w-20">Un.</th>
                    <th class="py-2 px-2 w-32" title="Deixe vazio para o item herdar a NF do PI.">NF</th>
                    <th class="py-2 px-2 w-28">Prazo (dias)</th><th class="py-2 px-2 w-10"></th>
                </tr></thead>
                {{-- Um <tbody> por item: a 1a linha e a grade principal, a 2a guarda os campos
                     descritivos, que nao cabem na largura da tabela. --}}
                <template x-for="(item, idx) in itens" :key="item._key">
                    <tbody class="border-b">
                        <tr>
                            <td class="py-1 px-2 text-gray-500" x-text="idx + 1"></td>
                            <td class="py-1 px-2"><input type="hidden" :name="`itens[${idx}][id]`" :value="item.id">
                                <input type="hidden" :name="`itens[${idx}][numero_item]`" :value="idx + 1">
                                <input :name="`itens[${idx}][cod_mmv]`" x-model="item.cod_mmv" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2"><input :name="`itens[${idx}][ni]`" x-model="item.ni" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2"><input :name="`itens[${idx}][descricao]`" x-model="item.descricao" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2"><input :name="`itens[${idx}][quantidade]`" x-model="item.quantidade" type="number" step="0.001" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2">
                                <select :name="`itens[${idx}][unidade_id]`" x-model="item.unidade_id" class="w-full rounded border-gray-300 text-sm">
                                    <option value="">—</option>
                                    @foreach ($unidades as $id => $sigla)<option value="{{ $id }}">{{ $sigla }}</option>@endforeach
                                </select></td>
                            {{-- NF do item: vazio herda a NF do PI; preenchida sobrescreve para este item. --}}
                            <td class="py-1 px-2"><input :name="`itens[${idx}][nf_cliente]`" x-model="item.nf_cliente"
                                                         :placeholder="nfPi ? 'PI: ' + nfPi : 'NF do PI'"
                                                         class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2"><input :name="`itens[${idx}][prazo_entrega_item]`" x-model="item.prazo_entrega_item" type="number" class="w-full rounded border-gray-300 text-sm"></td>
                            <td class="py-1 px-2 text-center"><button type="button" @click="remove(item._key)" class="text-red-500 hover:text-red-700">&times;</button></td>
                        </tr>
                        {{-- Campos descritivos do item. Aparecem no cabecalho do item na
                             Engenharia e na folha de processo. --}}
                        <tr>
                            <td></td>
                            <td colspan="8" class="pb-2 px-2">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                    <label class="block"><span class="block text-xs text-gray-500 mb-0.5">Material do cliente</span>
                                        <input :name="`itens[${idx}][material_cliente]`" x-model="item.material_cliente" class="w-full rounded border-gray-300 text-sm"></label>
                                    <label class="block"><span class="block text-xs text-gray-500 mb-0.5">Descrição do cliente</span>
                                        <input :name="`itens[${idx}][descricao_cliente]`" x-model="item.descricao_cliente" class="w-full rounded border-gray-300 text-sm"></label>
                                    <label class="block"><span class="block text-xs text-gray-500 mb-0.5">Observações do item</span>
                                        <input :name="`itens[${idx}][observacoes]`" x-model="item.observacoes" class="w-full rounded border-gray-300 text-sm"></label>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </template>
            </table>
        </div>
    </x-card>

    <div class="flex gap-2">
        <x-button type="submit">Salvar PI</x-button>
        <x-button variant="secondary" :href="route('liberacao.index')">Cancelar</x-button>
    </div>
</form>
@endsection
