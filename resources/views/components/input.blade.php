@props(['label' => null, 'name' => null, 'type' => 'text', 'value' => null])

<label class="block">
    @if ($label)<span class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</span>@endif
    <input type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $value) }}"
        {{ $attributes->merge(['class' => 'w-full rounded-md border-gray-300 shadow-sm focus:border-accent-500 focus:ring-accent-500 text-sm']) }}>
    @if ($name && $errors->has($name))
        <span class="block text-xs text-red-600 mt-1">{{ $errors->first($name) }}</span>
    @endif
</label>
