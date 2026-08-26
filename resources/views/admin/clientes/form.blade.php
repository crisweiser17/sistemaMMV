@extends('layouts.app')
@section('title', ($cliente->exists ? 'Editar ' : 'Novo ') . 'Cliente')

@php
    // Linhas iniciais do repeater: as unidades ja gravadas, ou o que o usuario
    // tinha digitado quando a validacao barrou o submit.
    // Normaliza as duas origens no mesmo formato: old() devolve "0"/"1" em texto,
    // e "0" seria verdadeiro no checkbox do Alpine.
    $unidadesIniciais = collect(old('unidades', $cliente->unidades->all()))
        ->map(fn ($u) => [
            'id' => data_get($u, 'id'),
            'nome' => data_get($u, 'nome') ?? '',
            'codigo' => data_get($u, 'codigo') ?? '',
            'ativo' => filter_var(data_get($u, 'ativo', true), FILTER_VALIDATE_BOOLEAN),
        ])
        ->values()->all();
    $templateUnidade = ['id' => null, 'nome' => '', 'codigo' => '', 'ativo' => true];
    // Erros de linha (unidades.0.nome, ...) sao listados juntos: o indice da linha
    // depois da filtragem das linhas vazias nao bate com a posicao na tela.
    $errosDeUnidade = collect($errors->getMessages())
        ->filter(fn ($msgs, $chave) => str_starts_with($chave, 'unidades'))
        ->flatten()->unique()->values();
@endphp

@section('content')
<form method="POST"
      action="{{ $cliente->exists ? route('admin.clientes.update', $cliente) : route('admin.clientes.store') }}"
      class="max-w-3xl"
      x-data="itemsRepeater(@js($unidadesIniciais), @js($templateUnidade))">
    @csrf
    @if ($cliente->exists) @method('PUT') @endif

    <x-card :title="($cliente->exists ? 'Editar ' : 'Novo ') . 'Cliente'" class="mb-4">
        <div class="space-y-4">
            <x-input label="Nome" name="nome" :value="$cliente->nome" />
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="ativo" value="0">
                <input type="checkbox" name="ativo" value="1" @checked(old('ativo', $cliente->exists ? $cliente->ativo : true)) class="rounded border-gray-300">
                Ativo
            </label>
        </div>
    </x-card>

    {{-- Unidades do cliente: um cliente atende uma ou mais plantas, e o codigo
         (ex.: Guaiba 24) e de cada unidade, nao do cliente. --}}
    <x-card title="Unidades" class="mb-4">
        <x-slot:actions><x-button variant="secondary" @click="add()">+ Adicionar Unidade</x-button></x-slot:actions>

        @if ($errosDeUnidade->isNotEmpty())
            <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errosDeUnidade as $erro)<li>{{ $erro }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b">
                    <th class="py-2 px-2">Nome</th>
                    <th class="py-2 px-2 w-32">Código</th>
                    <th class="py-2 px-2 w-20">Ativo</th>
                    <th class="py-2 px-2 w-10"></th>
                </tr></thead>
                {{-- Um <tbody> por unidade, no mesmo padrao do repeater de itens do PI. --}}
                <template x-for="(unidade, idx) in itens" :key="unidade._key">
                    <tbody class="border-b">
                        <tr>
                            <td class="py-1 px-2">
                                {{-- O id viaja no submit: sem ele a sincronizacao recriaria a
                                     unidade e os PIs/cotacoes perderiam o vinculo. --}}
                                <input type="hidden" :name="`unidades[${idx}][id]`" :value="unidade.id">
                                <input :name="`unidades[${idx}][nome]`" x-model="unidade.nome"
                                       placeholder="Ex.: Guaíba" class="w-full rounded border-gray-300 text-sm">
                            </td>
                            <td class="py-1 px-2">
                                <input :name="`unidades[${idx}][codigo]`" x-model="unidade.codigo"
                                       placeholder="Ex.: 24" class="w-full rounded border-gray-300 text-sm">
                            </td>
                            <td class="py-1 px-2 text-center">
                                <input type="hidden" :name="`unidades[${idx}][ativo]`" value="0">
                                <input type="checkbox" :name="`unidades[${idx}][ativo]`" value="1"
                                       x-model="unidade.ativo" class="rounded border-gray-300">
                            </td>
                            <td class="py-1 px-2 text-center">
                                <button type="button" @click="remove(unidade._key)" class="text-red-500 hover:text-red-700"
                                        title="Remover unidade">&times;</button>
                            </td>
                        </tr>
                    </tbody>
                </template>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500">
            Unidade já usada em PIs, cotações ou engenharia não pode ser removida — desmarque
            <strong>Ativo</strong> para tirá-la dos novos pedidos sem perder o histórico.
        </p>
    </x-card>

    <div class="flex gap-2">
        <x-button type="submit">Salvar</x-button>
        <x-button variant="secondary" :href="route('admin.clientes.index')">Cancelar</x-button>
    </div>
</form>
@endsection
