@props(['label' => null, 'name' => null, 'options' => [], 'value' => null, 'placeholder' => 'Selecione...'])

<label class="block">
    @if ($label)<span class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</span>@endif
    <select name="{{ $name }}"
        {{ $attributes->merge(['class' => 'w-full rounded-md border-gray-300 shadow-sm focus:border-accent-500 focus:ring-accent-500 text-sm']) }}>
        @if ($placeholder)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" @selected(old($name, $value) == $optValue)>{{ $optLabel }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if ($name && $errors->has($name))
        <span class="block text-xs text-red-600 mt-1">{{ $errors->first($name) }}</span>
    @endif
</label>
