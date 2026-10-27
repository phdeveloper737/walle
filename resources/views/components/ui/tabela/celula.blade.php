{{-- Célula. "numero" alinha à direita com algarismos de largura fixa; "destaque" usa a cor principal do texto. --}}
@props(['numero' => false, 'destaque' => false])

<td {{ $attributes->class([
    'px-2 py-1.5',
    'text-right tabular-nums' => $numero,
    'text-left' => ! $numero,
    'text-on-surface' => $destaque || ! $numero,
    'text-on-surface-variant' => $numero && ! $destaque,
]) }}>{{ $slot }}</td>
