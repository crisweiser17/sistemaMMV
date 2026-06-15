@props([
    'name' => 'arquivos',
    'multiple' => false,
    'accept' => '.pdf,.eml,.png,.jpg,.jpeg,.dwg,.dxf',
    'label' => 'Anexos',
])

{{-- Upload com preview do nome dos arquivos (Alpine). Validacao real no Form Request. --}}
<div x-data="{ files: [] }" class="space-y-2">
    <span class="block text-sm font-medium text-gray-700">{{ $label }}</span>
    <label class="flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-md p-4 cursor-pointer hover:border-accent-500">
        <span class="text-sm text-gray-500">Clique para selecionar {{ $multiple ? 'arquivos' : 'arquivo' }}</span>
        <span class="text-xs text-gray-400 mt-1">Max 20MB por arquivo</span>
        <input type="file" name="{{ $name }}{{ $multiple ? '[]' : '' }}" {{ $multiple ? 'multiple' : '' }}
               accept="{{ $accept }}" class="hidden"
               @change="files = Array.from($event.target.files).map(f => ({ name: f.name, size: (f.size/1024/1024).toFixed(2) }))">
    </label>
    <ul class="text-xs text-gray-600 space-y-1" x-show="files.length">
        <template x-for="f in files" :key="f.name">
            <li class="flex justify-between bg-gray-50 px-2 py-1 rounded">
                <span x-text="f.name"></span><span class="text-gray-400" x-text="f.size + ' MB'"></span>
            </li>
        </template>
    </ul>
    @error($name)<span class="block text-xs text-red-600">{{ $message }}</span>@enderror
</div>
