@props([
    'utility',
    /** list<array{short: string, full: string, value: int, estimated: bool}> du plus ancien au plus récent ; value en millièmes */
    'points' => [],
])

{{--
    Consommation mensuelle d'un fluide : colonnes fines (≤ 24 px, haut arrondi 4 px, base carrée),
    une seule série donc pas de légende de série (le titre la nomme). Mois estimés hachurés
    (codage secondaire, jamais la couleur seule). Infobulle au survol et au clavier ; le tableau
    sous le graphique sert de vue accessible.
--}}

@php
    $unit = $utility->unit();
    $n = count($points);
    $W = 320; $H = 168; $padL = 34; $padR = 6; $padT = 18; $padB = 22;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB; $base = $padT + $plotH;

    // Graduations rondes : pas « propre » (1, 2, 5 × 10^k) pour 4 intervalles au plus.
    $max = max(1, (int) ceil(max(array_column($points, 'value') ?: [0]) / 1000));
    $raw = $max / 4;
    $mag = 10 ** floor(log10($raw));
    $step = (int) max(1, collect([1, 2, 5, 10])->map(fn ($m) => $m * $mag)->first(fn ($s) => $s >= $raw));
    $top = (int) (ceil($max / $step) * $step);
    $ticks = range(0, $top, $step);

    $band = $n > 0 ? $plotW / $n : $plotW;
    $barW = min(24, max(4, $band - 6));
    $y = fn (float $v): float => $base - ($v / $top) * $plotH;
    $fmtTick = fn (int $t): string => number_format($t, 0, ',', "\u{00A0}");
    $showLabel = fn (int $k): bool => $n <= 6 || $k === $n - 1 || ($n - 1 - $k) % 2 === 0;
    $id = 'chart-'.$utility->value.'-'.\Illuminate\Support\Str::random(6);
    $anyEstimated = collect($points)->contains('estimated', true);

    $bar = function (float $x, float $yTop) use ($barW, $base): string {
        $h = $base - $yTop;
        $r = min(4, $h, $barW / 2);
        if ($h <= 0) {
            return '';
        }

        return sprintf('M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z',
            $x, $base, $yTop + $r, $x, $yTop, $x + $r, $yTop, $x + $barW - $r, $x + $barW, $yTop, $x + $barW, $yTop + $r, $base);
    };
@endphp

<figure {{ $attributes->class('space-y-2') }} x-data="{ i: null }">
    <figcaption class="text-sm font-medium text-sand-900">
        {{ \Illuminate\Support\Str::ucfirst($utility->label()) }} <span class="font-normal text-sand-600">({{ $unit }} par mois)</span>
    </figcaption>

    @if ($n === 0)
        <p class="text-sm text-sand-600">Pas encore de mois clôturé.</p>
    @else
        <div class="relative">
            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="block w-full h-auto overflow-visible" role="img"
                aria-label="Consommation d'{{ $utility->label() }} des {{ $n }} derniers mois clôturés ; détail dans le tableau.">
                <defs>
                    <pattern id="{{ $id }}-hatch" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                        <rect width="5" height="5" fill="var(--color-primary-200)" />
                        <line x1="0" y1="0" x2="0" y2="5" stroke="var(--color-primary-500)" stroke-width="2.5" />
                    </pattern>
                </defs>

                {{-- Grille et graduations : traits fins, discrets --}}
                @foreach ($ticks as $t)
                    <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y($t) }}" y2="{{ $y($t) }}"
                        class="{{ $t === 0 ? 'stroke-sand-300' : 'stroke-sand-200' }}" stroke-width="1" shape-rendering="crispEdges" />
                    <text x="{{ $padL - 5 }}" y="{{ $y($t) + 3 }}" text-anchor="end" class="fill-sand-500 text-[9px] tabular-nums">{{ $fmtTick($t) }}</text>
                @endforeach

                @foreach ($points as $k => $p)
                    @php
                        $cx = $padL + $band * ($k + 0.5);
                        $x = $cx - $barW / 2;
                        $yTop = $y($p['value'] / 1000);
                    @endphp
                    <g wire:key="{{ $id }}-{{ $k }}">
                        <path d="{{ $bar($x, $yTop) }}" fill="{{ $p['estimated'] ? 'url(#'.$id.'-hatch)' : 'var(--color-primary-500)' }}"
                            :opacity="i === null || i === {{ $k }} ? 1 : 0.45" />
                        @if ($showLabel($k))
                            <text x="{{ $cx }}" y="{{ $H - 6 }}" text-anchor="middle" class="fill-sand-500 text-[9px]">{{ $p['short'] }}</text>
                        @endif
                        @if ($k === $n - 1)
                            {{-- Libellé direct sur le dernier mois seulement --}}
                            <text x="{{ $cx }}" y="{{ $yTop - 5 }}" text-anchor="middle" class="fill-sand-800 text-[10px] font-semibold tabular-nums">{{ \App\Shared\Support\Milli::format($p['value']) }}</text>
                        @endif
                        {{-- Zone de survol plus large que la colonne --}}
                        <rect x="{{ $padL + $band * $k }}" y="{{ $padT }}" width="{{ $band }}" height="{{ $plotH }}" fill="transparent"
                            tabindex="0" class="cursor-default focus:outline-none"
                            aria-label="{{ $p['full'] }} : {{ \App\Shared\Support\Milli::format($p['value']) }} {{ $unit }}{{ $p['estimated'] ? ', estimé' : '' }}"
                            @mouseenter="i = {{ $k }}" @mouseleave="i = null" @focus="i = {{ $k }}" @blur="i = null" />
                    </g>
                @endforeach
            </svg>

            @foreach ($points as $k => $p)
                @php
                    $left = ($padL + $band * ($k + 0.5)) / $W * 100;
                @endphp
                <div x-show="i === {{ $k }}" x-cloak role="tooltip"
                    class="pointer-events-none absolute -top-2 z-10 -translate-y-full whitespace-nowrap rounded-[var(--radius)] border border-sand-200 bg-white px-2 py-1 text-xs shadow-md"
                    style="left: clamp(0%, calc({{ number_format($left, 2, '.', '') }}% - 3.5rem), calc(100% - 7rem))">
                    <p class="font-medium text-sand-900">{{ $p['full'] }}</p>
                    <p class="tabular-nums text-sand-700">{{ \App\Shared\Support\Milli::format($p['value']) }} {{ $unit }}{{ $p['estimated'] ? ' · estimé' : '' }}</p>
                </div>
            @endforeach
        </div>

        @if ($anyEstimated)
            <p class="flex items-center gap-2 text-xs text-sand-600">
                <svg width="14" height="10" aria-hidden="true"><rect width="14" height="10" rx="2" fill="url(#{{ $id }}-hatch)" /></svg>
                Hachuré : mois facturé sur un relevé estimé.
            </p>
        @endif
    @endif
</figure>
