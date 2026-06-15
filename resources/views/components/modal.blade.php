@props([
    'name' => 'modal',   // nome para abrir via $dispatch('open-modal','name') ou x-model externo
    'maxWidth' => '2xl',
])

{{-- Modal reutilizavel. Controlado por uma propriedade Alpine no escopo pai (entangle)
     ou por eventos. Uso simples: <x-modal x-model="aberto"> ... </x-modal> --}}
<div
    x-data="{ open: false }"
    x-modelable="open"
    {{ $attributes->only('x-model') }}
    x-show="open"
    x-on:keydown.escape.window="open = false"
    style="display:none"
    class="fixed inset-0 z-40 overflow-y-auto"
>
    <div class="flex items-center justify-center min-h-screen p-4">
        <div x-show="open" x-transition.opacity @click="open = false" class="fixed inset-0 bg-black/50"></div>

        <div x-show="open" x-transition
             class="relative bg-white rounded-lg shadow-xl w-full max-w-{{ $maxWidth }} max-h-[85vh] overflow-auto">
            @isset($header)
                <div class="px-6 py-4 border-b flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800">{{ $header }}</h3>
                    <button @click="open = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
                </div>
            @endisset

            <div class="px-6 py-4">{{ $slot }}</div>

            @isset($footer)
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-2">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
