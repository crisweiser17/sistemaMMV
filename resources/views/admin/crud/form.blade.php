@extends('layouts.app')
@section('title', ($registro ? 'Editar ' : 'Novo ') . $config['singular'])

@section('content')
<x-card :title="($registro ? 'Editar ' : 'Novo ') . $config['singular']" class="max-w-2xl">
    <form method="POST"
          action="{{ $registro ? route('admin.'.$config['slug'].'.update', $registro) : route('admin.'.$config['slug'].'.store') }}"
          class="space-y-4">
        @csrf
        @if ($registro) @method('PUT') @endif

        @php
            // O nome do Administrador e o que reconhece o acesso de admin: fica travado.
            $nomeTravado = $registro instanceof \App\Models\Perfil && $registro->ehAdministrador();
        @endphp

        @foreach ($config['fields'] as $f)
            @php $valor = old($f['name'], $registro?->{$f['name']}); @endphp

            @if ($nomeTravado && $f['name'] === 'nome')
                <div>
                    <x-input :label="$f['label']" :name="$f['name']" :value="$valor" readonly class="bg-gray-50 text-gray-500" />
                    <span class="block text-xs text-gray-500 mt-1">O perfil Administrador não pode ser renomeado.</span>
                </div>
            @elseif ($f['type'] === 'password')
                {{-- Nunca preenchido: o valor gravado e o hash, e senha nao volta no old(). --}}
                <div>
                    <x-input :label="$f['label']" :name="$f['name']" type="password" value="" autocomplete="new-password" />
                    <span class="block text-xs text-gray-500 mt-1">
                        {{ $registro ? 'Deixe em branco para manter a senha atual.' : 'Mínimo de 8 caracteres.' }}
                    </span>
                </div>
            @elseif ($f['type'] === 'boolean')
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
            @elseif ($f['type'] === 'permissoes')
                @include('admin.crud.partials.permissoes', ['f' => $f, 'registro' => $registro])
            @elseif ($f['type'] === 'usuarios')
                @include('admin.crud.partials.usuarios', ['f' => $f, 'registro' => $registro])
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
