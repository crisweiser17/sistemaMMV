@extends('layouts.app')
@section('title', $config['label'])

@section('content')
@php $listFields = collect($config['fields'])->where('list', true); @endphp

<x-card :title="$config['label']">
    <x-slot:actions>
        <x-button :href="route('admin.'.$config['slug'].'.create')">+ Novo {{ $config['singular'] }}</x-button>
    </x-slot:actions>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    @foreach ($listFields as $f)
                        <th class="py-2 pr-4 font-medium">{{ $f['label'] }}</th>
                    @endforeach
                    <th class="py-2 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($registros as $registro)
                    <tr class="hover:bg-gray-50">
                        @foreach ($listFields as $f)
                            <td class="py-2 pr-4">
                                @if (isset($f['display']))
                                    {{ data_get($registro, $f['display']) ?? '—' }}
                                @elseif ($f['type'] === 'boolean')
                                    <span class="px-2 py-0.5 rounded-full text-xs {{ $registro->{$f['name']} ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $registro->{$f['name']} ? 'Sim' : 'Não' }}
                                    </span>
                                @elseif ($f['type'] === 'color')
                                    <span class="inline-flex items-center gap-2">
                                        <span class="w-4 h-4 rounded" style="background: {{ $registro->{$f['name']} }}"></span>
                                        {{ $registro->{$f['name']} }}
                                    </span>
                                @elseif ($f['type'] === 'select' && isset($f['_options']))
                                    {{ $f['_options'][$registro->{$f['name']}] ?? $registro->{$f['name']} }}
                                @else
                                    {{ $registro->{$f['name']} ?? '—' }}
                                @endif
                            </td>
                        @endforeach
                        <td class="py-2 text-right whitespace-nowrap">
                            <a href="{{ route("admin.{$config['slug']}.edit", $registro) }}" class="text-accent-700 hover:underline">Editar</a>
                            <form method="POST" action="{{ route("admin.{$config['slug']}.destroy", $registro) }}" class="inline"
                                  onsubmit="return confirm('Remover este registro?')">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline ml-3">Excluir</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $listFields->count() + 1 }}" class="py-6 text-center text-gray-400">Nenhum registro.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $registros->links() }}</div>
</x-card>
@endsection
