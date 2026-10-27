{{-- Cabeçalho de coluna (th com scope). "numero" alinha à direita. --}}
@props(['numero' => false])

<th scope="col" {{ $attributes->class(['border-b border-outline-variant px-2 py-1.5 font-medium', 'text-right' => $numero, 'text-left' => ! $numero]) }}>{{ $slot }}</th>
