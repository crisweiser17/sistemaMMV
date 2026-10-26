{{-- Usuarios que recebem este perfil. Cada usuario tem um perfil so: marcar alguem que
     esta em outro perfil o transfere para este. Envia usuarios[] = ids marcados. --}}
@php
    $enviado = old($f['name']);
    $marcados = is_array($enviado)
        ? array_map('intval', array_filter($enviado))
        : ($registro ? $f['_usuarios']->where('perfil_id', $registro->id)->pluck('id')->all() : []);
@endphp

<fieldset x-data="{ busca: '' }">
    <div class="flex flex-wrap items-end justify-between gap-2 mb-2">
        <div>
            <legend class="block text-sm font-medium text-gray-700">{{ $f['label'] }}</legend>
            <p class="text-xs text-gray-500">Marque quem terá as permissões deste perfil.</p>
        </div>
        <input type="search" x-model="busca" placeholder="Buscar usuário..."
               class="rounded-md border-gray-300 shadow-sm text-sm focus:border-accent-500 focus:ring-accent-500">
    </div>

    {{-- Garante a chave no request mesmo sem nenhum marcado (= tirar todos do perfil). --}}
    <input type="hidden" name="{{ $f['name'] }}[]" value="">

    <div class="rounded-lg border border-gray-200 divide-y max-h-72 overflow-y-auto">
        @forelse ($f['_usuarios'] as $u)
            @php $outroPerfil = $u->perfil && $u->perfil_id !== $registro?->id ? $u->perfil->nome : null; @endphp
            <label class="flex items-center gap-3 px-4 py-2 cursor-pointer hover:bg-gray-50"
                   x-show="busca === '' || @js(mb_strtolower($u->name.' '.$u->email)).includes(busca.toLowerCase())">
                <input type="checkbox" name="{{ $f['name'] }}[]" value="{{ $u->id }}"
                       @checked(in_array($u->id, $marcados, true)) class="rounded border-gray-300">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm text-gray-800 truncate">
                        {{ $u->name }}
                        @unless ($u->ativo)<span class="text-xs text-gray-400">(inativo)</span>@endunless
                    </span>
                    <span class="block text-xs text-gray-500 truncate">{{ $u->email }}</span>
                </span>
                @if ($outroPerfil)
                    <span class="text-xs text-gray-500 whitespace-nowrap">hoje: {{ $outroPerfil }}</span>
                @elseif (! $u->perfil)
                    <span class="text-xs text-gray-400 whitespace-nowrap">sem perfil</span>
                @endif
            </label>
        @empty
            <p class="px-4 py-3 text-sm text-gray-400">Nenhum usuário cadastrado.</p>
        @endforelse
    </div>

    <p class="text-xs text-gray-500 mt-2">
        Marcar um usuário que está em outro perfil o move para este. Desmarcar deixa o usuário sem perfil (sem acesso).
    </p>
    @if ($errors->has($f['name'].'.*'))
        <span class="block text-xs text-red-600 mt-1">Usuário inválido.</span>
    @endif
</fieldset>
