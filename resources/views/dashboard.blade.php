@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    @foreach ($cards as $card)
        <a href="{{ route($card['rota']) }}" class="block">
            <x-card class="hover:shadow-md transition">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm text-gray-500">{{ $card['label'] }}</div>
                        <div class="text-3xl font-bold mt-1" style="color: {{ $card['cor'] }}">{{ $card['valor'] }}</div>
                    </div>
                    <div class="w-10 h-10 rounded-full" style="background: {{ $card['cor'] }}22"></div>
                </div>
            </x-card>
        </a>
    @endforeach
</div>

<x-card title="Bem-vindo ao Sistema MMV" class="mt-6">
    <p class="text-sm text-gray-600">
        Use o menu lateral para navegar entre Cotações, Liberações (PI), Controle de Demandas, Engenharia e Cadastros.
        O Controle de Demandas atualiza em <b>tempo real</b> — alterações feitas por outros usuários aparecem sem recarregar a página.
    </p>
</x-card>
@endsection
