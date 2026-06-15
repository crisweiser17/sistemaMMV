@extends('layouts.app')
@section('title', 'Entrar')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gray-100 px-4">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <img src="{{ asset('img/mmvlogo.png') }}" alt="MMV Equipamentos" class="h-12 w-auto mx-auto">
            <p class="text-sm text-gray-500 mt-2">Gestão de Pedidos · Equipamentos Industriais</p>
        </div>

        <x-card>
            @if (session('error'))
                <div class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">
                    {{ session('error') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <x-input label="E-mail" name="email" type="email" required autofocus />
                <x-input label="Senha" name="password" type="password" required />
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="remember" class="rounded border-gray-300"> Manter conectado
                </label>
                <x-button type="submit" class="w-full justify-center">Entrar</x-button>
            </form>
        </x-card>

        <p class="text-center text-xs text-gray-400 mt-4">
            Teste: admin@mmv.com · engenharia@mmv.com · comercial@mmv.com · consulta@mmv.com — senha <b>password</b>
        </p>
    </div>
</div>
@endsection
