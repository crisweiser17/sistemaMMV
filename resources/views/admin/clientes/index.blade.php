@extends('layouts.app')
@section('title', 'Clientes')

@section('content')
<x-card title="Clientes">
    <x-slot:actions>
        <x-button :href="route('admin.clientes.create')">+ Novo Cliente</x-button>
    </x-slot:actions>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2 pr-4 font-medium">Nome</th>
                    <th class="py-2 pr-4 font-medium">Unidades</th>
                    <th class="py-2 pr-4 font-medium">Ativo</th>
                    <th class="py-2 text-right">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($clientes as $cliente)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2 pr-4">{{ $cliente->nome }}</td>
                        <td class="py-2 pr-4">
                            @forelse ($cliente->unidades as $unidade)
                                <span class="inline-flex items-center gap-1 mr-1 mb-1 px-2 py-0.5 rounded-full text-xs {{ $unidade->ativo ? 'bg-gray-100 text-gray-700' : 'bg-gray-50 text-gray-400 line-through' }}">
                                    {{ $unidade->nome }}
                                    @if ($unidade->codigo)<span class="text-gray-500">· {{ $unidade->codigo }}</span>@endif
                                </span>
                            @empty
                                <span class="text-gray-400">Sem unidades</span>
                            @endforelse
                        </td>
                        <td class="py-2 pr-4">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $cliente->ativo ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $cliente->ativo ? 'Sim' : 'Não' }}
                            </span>
                        </td>
                        <td class="py-2 text-right whitespace-nowrap">
                            <a href="{{ route('admin.clientes.edit', $cliente) }}" class="text-accent-700 hover:underline">Editar</a>
                            <form method="POST" action="{{ route('admin.clientes.destroy', $cliente) }}" class="inline"
                                  onsubmit="return confirm('Remover este cliente?')">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:underline ml-3">Excluir</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">Nenhum registro.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $clientes->links() }}</div>
</x-card>
@endsection
