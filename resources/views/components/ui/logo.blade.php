{{-- Logo do walle: os dois olhos do robô (teal e areia) + o nome. Ex.: <x-ui.logo /> · <x-ui.logo grande /> --}}
@props([
    'grande' => false,
    'soOlhos' => false,
])

<span {{ $attributes->class('inline-flex items-center gap-2.5') }}>
    <svg viewBox="0 0 52 30" @class(['w-auto', 'h-12' => $grande, 'h-7' => ! $grande]) aria-hidden="true">
        <g transform="rotate(-8 12 15)">
            <rect x="1" y="4" width="22" height="22" rx="9" fill="var(--color-primary-container)" />
            <circle cx="13" cy="15" r="5.5" fill="var(--color-surface-container-lowest)" />
            <circle cx="14.8" cy="13.2" r="1.7" fill="var(--color-on-surface)" />
        </g>
        <g transform="rotate(8 40 15)">
            <rect x="29" y="4" width="22" height="22" rx="9" fill="var(--color-secondary)" />
            <circle cx="39" cy="15" r="5.5" fill="var(--color-surface-container-lowest)" />
            <circle cx="40.8" cy="13.2" r="1.7" fill="var(--color-on-surface)" />
        </g>
    </svg>
    @unless ($soOlhos)
        <span @class(['font-medium tracking-wide text-on-surface', 'text-4xl' => $grande, 'text-xl' => ! $grande])>walle</span>
    @endunless
</span>
