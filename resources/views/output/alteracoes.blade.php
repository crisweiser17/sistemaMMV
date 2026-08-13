@extends('layouts.app')
@section('title', 'Alterações · ' . $numeroReferencia)

@section('content')
{{-- Tela propria (e nao um bloco no historico de PDFs): a lista e cronologica e por
     campo, e o historico de PDFs trata de outra coisa — as versoes do documento. --}}
<x-card :title="'Alterações após a liberação — ' . $numeroReferencia">
    <x-slot:actions>
        <x-button variant="secondary" :href="route('engenharia.demanda', $demanda)">← Voltar à engenharia</x-button>
        <x-button variant="secondary" :href="route('output.preview', $demanda)">👁 Preview PI</x-button>
        <x-button variant="secondary" :href="route('output.historico', $demanda)">📄 PDFs</x-button>
    </x-slot:actions>

    @if (! $marco)
        <p class="text-sm text-gray-500">
            Este processo ainda não teve PDF gerado. Nada é marcado como alteração antes da primeira
            liberação — até lá o detalhamento ainda não foi entregue a produção nem a compras.
        </p>
    @elseif (! $eventos)
        <p class="text-sm text-gray-500">
            Nenhuma alteração desde o último PDF, gerado em {{ $marco->format('d/m/Y H:i') }}.
        </p>
    @else
        <div class="mb-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            <span class="font-semibold uppercase tracking-wide">Processo alterado após a liberação</span> —
            {{ $total }} {{ $total === 1 ? 'alteração registrada' : 'alterações registradas' }}
            desde o último PDF, gerado em {{ $marco->format('d/m/Y H:i') }}.
            Gerar um PDF novo torna a versão atual a vigente e zera esta marcação.
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b">
                    <th class="py-2 pr-4">Registro</th>
                    <th class="py-2 pr-4">Campo</th>
                    <th class="py-2 pr-4">Valor anterior</th>
                    <th class="py-2 pr-4">Valor novo</th>
                    <th class="py-2 pr-4">Data</th>
                    <th class="py-2">Usuário</th>
                </tr></thead>
                <tbody class="divide-y">
                    @foreach ($eventos as $e)
                        <tr>
                            <td class="py-2 pr-4">
                                {{ $e['registro'] }}
                                @if ($e['campo'] === null)
                                    <span class="ml-1 inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold bg-red-100 text-red-700 border border-red-200">{{ $e['evento'] }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4">{{ $e['campo'] ?? '—' }}</td>
                            <td class="py-2 pr-4 text-gray-500 line-through">{{ $e['de'] ?? '—' }}</td>
                            <td class="py-2 pr-4 font-medium text-red-700">{{ $e['para'] ?? '—' }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ optional($e['data'])->format('d/m/Y H:i') }}</td>
                            <td class="py-2">{{ $e['usuario'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>
@endsection
