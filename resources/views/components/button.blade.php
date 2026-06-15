@props(['variant' => 'primary', 'as' => 'button', 'href' => null])

@php
    $styles = [
        // Acao principal: laranja da marca com texto escuro (contraste ~8:1; branco reprovaria).
        'primary' => 'bg-accent-500 hover:bg-accent-600 text-[#1E1E1E]',
        'secondary' => 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50',
        'danger' => 'bg-red-600 hover:bg-red-700 text-white',
        'ghost' => 'text-accent-700 hover:bg-accent-50',
    ];
    $base = 'inline-flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed';
    $cls = $base . ' ' . ($styles[$variant] ?? $styles['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $cls]) }}>{{ $slot }}</button>
@endif
