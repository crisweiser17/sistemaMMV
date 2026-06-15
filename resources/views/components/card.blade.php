@props(['title' => null])

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg shadow-sm border border-gray-200']) }}>
    @if ($title || isset($actions))
        <div class="px-5 py-3 border-b flex items-center justify-between">
            <h2 class="font-semibold text-gray-800">{{ $title }}</h2>
            @isset($actions)<div class="flex gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="p-5">{{ $slot }}</div>
</div>
