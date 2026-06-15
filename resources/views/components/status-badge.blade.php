@props(['status' => null])

{{-- Badge colorido a partir do StatusEngenharia (usa cor_hex). --}}
@if ($status)
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
          style="background-color: {{ $status->cor_hex }}1a; color: {{ $status->cor_hex }}; border: 1px solid {{ $status->cor_hex }}40;">
        {{ $status->nome }}
    </span>
@else
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">—</span>
@endif
