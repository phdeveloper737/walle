{{-- Linha da tabela. Repassa atributos, ex.: wire:key. --}}
<tr {{ $attributes->class('border-b border-outline-variant/50 last:border-0') }}>{{ $slot }}</tr>
