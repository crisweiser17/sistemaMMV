<!DOCTYPE html>
<html lang="pt-BR" class="h-full bg-gray-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'MMV') · MMV Equipamentos</title>

    {{-- Tailwind via CDN (sem build step) --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: {
                // "Chrome" estrutural — neutro escuro (substitui o antigo azul da marca).
                mmv: { 700: '#1E1E1E', 600: '#2A2A2A', 500: '#404040', 50: '#F5F5F4' },
                // Laranja de acao. 500 = fundo de botao (texto escuro por cima);
                // 700 = "tinta" para texto/links/icones sobre fundo claro (contraste ~5:1).
                accent: { 50: '#FDF1E7', 400: '#F59B56', 500: '#EF8332', 600: '#E06E18', 700: '#A8500F' },
            } } }
        }
    </script>

    {{-- Padronizacao global do tamanho dos campos (altura/padding maiores).
         !important para sobrepor utilitarios do Tailwind CDN (ex.: py-1, text-sm). --}}
    <style>
        /* Esconde elementos com x-cloak ate o Alpine inicializar (evita "flash" de dropdowns/modais). */
        [x-cloak] { display: none !important; }

        /* Aumento global suave: escala todas as medidas em rem do Tailwind (fontes, paddings). */
        html { font-size: 17.5px; }

        input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=color]):not([type=range]),
        select,
        textarea {
            min-height: 2.75rem !important;          /* ~44px */
            padding-top: 0.625rem !important;
            padding-bottom: 0.625rem !important;
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
            font-size: 0.9375rem !important;         /* ~15px, levemente maior */
            line-height: 1.4 !important;
            background-color: #F5F5F4 !important;    /* cinza neutro suave para destacar do fundo branco */
            border-color: #d4d4d4 !important;        /* borda um pouco mais visivel */
        }
        /* Ao focar, fundo branco + realce laranja: deixa claro onde o usuario esta digitando */
        input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=color]):not([type=range]):focus,
        select:focus,
        textarea:focus {
            background-color: #ffffff !important;
            border-color: #EF8332 !important;
            box-shadow: 0 0 0 2px rgba(239, 131, 50, 0.25) !important;
            outline: none !important;
        }
        input::placeholder, textarea::placeholder { color: #94a3b8 !important; }
        textarea { min-height: 5rem !important; }
        select { padding-right: 2rem !important; }   /* espaco para a seta */
        /* Em tabelas densas, mantem confortavel mas um pouco mais compacto */
        td input:not([type=checkbox]):not([type=radio]),
        td select {
            min-height: 2.5rem !important;
        }
    </style>

    {{-- Real-time: Pusher protocol + Laravel Echo (sincronos, antes do app.js) --}}
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>

    {{-- Config exposta ao JS a partir do .env --}}
    <script>
        window.MMV_CONFIG = {
            csrf: '{{ csrf_token() }}',
            reverb: {
                key: '{{ config('reverb.apps.apps.0.key') }}',
                host: '{{ config('reverb.apps.apps.0.options.host') ?: request()->getHost() }}',
                port: {{ config('reverb.apps.apps.0.options.port', 8080) }},
                scheme: '{{ config('reverb.apps.apps.0.options.scheme', 'http') }}'
            }
        };
    </script>

    {{-- Bootstrap Echo + helpers globais e fabricas Alpine (deferred, servidos estaticos) --}}
    <script defer src="{{ asset('js/app.js') }}"></script>
    <script defer src="{{ asset('js/alpine/factories.js') }}"></script>

    {{-- Alpine por ultimo (defer preserva ordem: helpers/fabricas registram antes do init) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="h-full">
@auth
<div class="min-h-full flex" x-data="{ sidebar: false }" @keydown.escape.window="sidebar = false">
    {{-- Sidebar fixa (desktop) --}}
    <aside class="w-60 bg-mmv-700 text-gray-100 flex-shrink-0 hidden md:block">
        <div class="h-16 flex items-center px-6 border-b border-white/10">
            <span class="inline-flex items-center bg-white rounded-md px-2 py-1">
                <img src="{{ asset('img/mmvlogo.png') }}" alt="MMV" class="h-6 w-auto">
            </span>
        </div>
        <nav class="p-3 space-y-1 text-sm">
            @include('partials.nav-links')
        </nav>
    </aside>

    {{-- Drawer mobile (overlay + painel deslizante). Reutiliza os mesmos itens de navegacao. --}}
    <div x-show="sidebar" x-cloak class="fixed inset-0 z-40 md:hidden" role="dialog" aria-modal="true">
        <div x-show="sidebar" x-transition.opacity @click="sidebar = false" class="fixed inset-0 bg-black/50"></div>
        <aside x-show="sidebar"
               x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
               class="relative w-64 max-w-[80%] h-full bg-mmv-700 text-gray-100 flex flex-col">
            <div class="h-16 flex items-center justify-between px-4 border-b border-white/10">
                <span class="inline-flex items-center bg-white rounded-md px-2 py-1">
                    <img src="{{ asset('img/mmvlogo.png') }}" alt="MMV" class="h-6 w-auto">
                </span>
                <button @click="sidebar = false" class="text-white/70 hover:text-white p-2 -mr-2" aria-label="Fechar menu">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <nav class="p-3 space-y-1 text-sm overflow-y-auto" @click="sidebar = false">
                @include('partials.nav-links')
            </nav>
        </aside>
    </div>

    @php $u = auth()->user(); @endphp

    {{-- Conteudo --}}
    <div class="flex-1 flex flex-col min-w-0">
        <header class="h-16 bg-white border-b flex items-center justify-between gap-3 px-4 md:px-6">
            <div class="flex items-center gap-3 min-w-0">
                {{-- Hamburguer: abre o drawer (apenas mobile) --}}
                <button @click="sidebar = true" class="md:hidden text-gray-600 hover:text-gray-900 p-2 -ml-2" aria-label="Abrir menu">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" /></svg>
                </button>
                <h1 class="text-lg font-semibold text-gray-800 truncate">@yield('title', 'Dashboard')</h1>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-gray-600 hidden sm:inline">{{ $u->name }} · <span class="text-gray-400">{{ $u->perfil?->nome }}</span></span>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="text-red-600 hover:underline">Sair</button>
                </form>
            </div>
        </header>

        <main class="flex-1 p-6 overflow-auto">
            @yield('content')
        </main>
    </div>

    {{-- Toast global reutilizavel --}}
    <x-toast />
</div>
@endauth

@guest
    @yield('content')
@endguest
</body>
</html>
