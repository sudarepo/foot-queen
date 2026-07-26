<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            How the split works
        </x-slot>

        Real visitors are split 50/50 between the grid and feed layouts on "/", sticky
        per-visitor via a cookie so the same person keeps seeing the same variant.
        Bots/crawlers are excluded entirely — they always see the grid, and never
        appear in these numbers.
    </x-filament::section>
</x-filament-panels::page>
