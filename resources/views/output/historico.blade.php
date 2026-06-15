@extends('layouts.app')
@section('title', 'PDFs · ' . $numeroReferencia)

@section('content')
<x-card :title="'Histórico de PDFs — ' . $numeroReferencia">
    <x-slot:actions>
        <x-button variant="secondary" :href="route('engenharia.demanda', $demanda)">← Voltar à engenharia</x-button>
        @can('editar', 'engenharia')
            <form method="POST" action="{{ route('output.gerar', $demanda) }}">@csrf<x-button type="submit">Gerar novo PDF</x-button></form>
        @endcan
    </x-slot:actions>

    <table class="min-w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b">
            <th class="py-2 pr-4">Gerado em</th><th class="py-2 pr-4">Por</th><th class="py-2 text-right">Arquivo</th>
        </tr></thead>
        <tbody class="divide-y">
            @forelse ($demanda->outputs as $o)
                <tr>
                    <td class="py-2 pr-4">{{ optional($o->gerado_em)->format('d/m/Y H:i') }}</td>
                    <td class="py-2 pr-4">{{ $o->autor?->name ?? '—' }}</td>
                    <td class="py-2 text-right"><a href="{{ route('output.download', $demanda) }}" class="text-accent-700 hover:underline">Baixar último</a></td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-6 text-center text-gray-400">Nenhum PDF gerado ainda.</td></tr>
            @endforelse
        </tbody>
    </table>
</x-card>
@endsection
