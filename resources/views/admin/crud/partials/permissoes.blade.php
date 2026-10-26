{{-- Grade de permissoes por modulo: um seletor "Sem acesso / Ver / Editar" por linha,
     no lugar do JSON cru. Envia permissoes[<modulo>] = nenhum|ver|editar. --}}
@use('App\Models\Perfil')
@php
    $enviado = old($f['name']);
    $niveis = collect(Perfil::MODULOS)->map(fn ($m, $modulo) => is_array($enviado)
        ? ($enviado[$modulo] ?? 'nenhum')
        : ($registro?->nivelEm($modulo) ?? 'nenhum'))->all();

    $estilos = [
        'nenhum' => 'peer-checked:bg-gray-600 peer-checked:text-white peer-checked:border-gray-600',
        'ver' => 'peer-checked:bg-sky-600 peer-checked:text-white peer-checked:border-sky-600',
        'editar' => 'peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-600',
    ];
@endphp

<fieldset x-data="{ niveis: @js($niveis), todos(nivel) { Object.keys(this.niveis).forEach(m => this.niveis[m] = nivel) } }">
    <div class="flex flex-wrap items-end justify-between gap-2 mb-2">
        <div>
            <legend class="block text-sm font-medium text-gray-700">{{ $f['label'] }}</legend>
            <p class="text-xs text-gray-500">Escolha o que este perfil pode fazer em cada área do sistema.</p>
        </div>
        <div class="flex items-center gap-1 text-xs text-gray-500">
            <span>Marcar todos:</span>
            @foreach (Perfil::NIVEIS as $nivel => $rotulo)
                <button type="button" @click="todos('{{ $nivel }}')"
                        class="px-2 py-1 rounded border border-gray-300 hover:bg-gray-100 text-gray-700">{{ $rotulo }}</button>
            @endforeach
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 divide-y">
        @foreach (Perfil::MODULOS as $modulo => $info)
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 py-3">
                <div>
                    <div class="text-sm font-medium text-gray-800">{{ $info['label'] }}</div>
                    <div class="text-xs text-gray-500">{{ $info['descricao'] }}</div>
                </div>
                <div class="inline-flex rounded-md shadow-sm" role="radiogroup" aria-label="{{ $info['label'] }}">
                    @foreach (Perfil::NIVEIS as $nivel => $rotulo)
                        <label class="cursor-pointer">
                            <input type="radio" class="peer sr-only"
                                   name="{{ $f['name'] }}[{{ $modulo }}]" value="{{ $nivel }}"
                                   x-model="niveis.{{ $modulo }}" @checked($niveis[$modulo] === $nivel)>
                            <span class="block px-3 py-1.5 text-sm border border-gray-300 bg-white text-gray-600 hover:bg-gray-50
                                         {{ $loop->first ? 'rounded-l-md' : '-ml-px' }} {{ $loop->last ? 'rounded-r-md' : '' }}
                                         peer-focus-visible:ring-2 peer-focus-visible:ring-accent-500 peer-focus-visible:z-10 relative
                                         {{ $estilos[$nivel] }}">{{ $rotulo }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-500 mt-2">
        <b>Ver</b>: consulta as telas, sem botões de criar, editar ou excluir.
        <b>Editar</b>: acesso completo à área.
    </p>
    @if ($errors->has($f['name'].'.*'))
        <span class="block text-xs text-red-600 mt-1">Nível de acesso inválido.</span>
    @endif
</fieldset>
