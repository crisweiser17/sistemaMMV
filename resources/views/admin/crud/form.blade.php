@extends('layouts.app')
@section('title', ($registro ? 'Editar ' : 'Novo ') . $config['singular'])

@section('content')
<x-card :title="($registro ? 'Editar ' : 'Novo ') . $config['singular']" class="max-w-2xl">
    <form method="POST"
          action="{{ $registro ? route('admin.'.$config['slug'].'.update', $registro) : route('admin.'.$config['slug'].'.store') }}"
          class="space-y-4">
        @csrf
        @if ($registro) @method('PUT') @endif

        @foreach ($config['fields'] as $f)
            @php $valor = old($f['name'], $registro?->{$f['name']}); @endphp

            @if ($f['type'] === 'boolean')
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="hidden" name="{{ $f['name'] }}" value="0">
                    <input type="checkbox" name="{{ $f['name'] }}" value="1" @checked($valor) class="rounded border-gray-300">
                    {{ $f['label'] }}
                </label>
            @elseif ($f['type'] === 'select')
                <x-select :label="$f['label']" :name="$f['name']" :options="$f['_options'] ?? []" :value="$valor" />
            @elseif ($f['type'] === 'textarea')
                <label class="block">
                    <span class="block text-sm font-medium text-gray-700 mb-1">{{ $f['label'] }}</span>
                    <textarea name="{{ $f['name'] }}" rows="3"
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-accent-500 focus:ring-accent-500 text-sm">{{ $valor }}</textarea>
                </label>
            @elseif ($f['type'] === 'json')
                <label class="block">
                    <span class="block text-sm font-medium text-gray-700 mb-1">{{ $f['label'] }}</span>
                    <textarea name="{{ $f['name'] }}" rows="5"
                        class="w-full rounded-md border-gray-300 shadow-sm font-mono text-xs">{{ is_array($valor) ? json_encode($valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $valor }}</textarea>
                    <span class="text-xs text-gray-400">Ex.: {"cotacao":"editar","engenharia":"ver"}</span>
                </label>
            @elseif ($f['type'] === 'color')
                <label class="block">
                    <span class="block text-sm font-medium text-gray-700 mb-1">{{ $f['label'] }}</span>
                    <input type="color" name="{{ $f['name'] }}" value="{{ $valor ?: '#6b7280' }}" class="h-10 w-20 rounded border-gray-300">
                </label>
            @else
                <x-input :label="$f['label']" :name="$f['name']" :type="$f['type']" :value="$valor" />
            @endif
        @endforeach

        <div class="flex gap-2 pt-2">
            <x-button type="submit">Salvar</x-button>
            <x-button variant="secondary" :href="route('admin.'.$config['slug'].'.index')">Cancelar</x-button>
        </div>
    </form>
</x-card>
@endsection
