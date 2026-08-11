{{--
    The same shape as Statamic's own Updates entry, so it reads as part of the
    control panel rather than as something bolted on.

    The badge is Statamic's own `ui-badge`, with the props their UpdatesBadge
    passes: amber, small, pill. Hand-rolled classes would match today and drift
    the first time they restyle the panel, and this is the one piece of our
    interface living inside somebody else's chrome.

    Rendered server side rather than as a Vue component. Theirs fetches a count
    over XHR; ours comes from another application entirely and is cached, so
    there is nothing to fetch asynchronously. A number a few minutes stale is
    fine for a queue somebody works through weekly.
--}}
@php
    $waiting = app(AltDesign\Altimiser\Altimiser::class)->summary()['awaiting_review'] ?? 0;
@endphp

<li>
    <inertia-link href="{{ $item->url() }}" class="flex items-center gap-2 sm:gap-3 {{ $item->isActive() ? 'active' : '' }}">
        {{-- The item's own icon rather than a second copy of it here, so the
             sidebar and anything else that renders this entry cannot disagree.
             Statamic sanitises it and adds the sizing classes. --}}
        {!! $item->svg() !!}
        <span v-pre>{{ $item->name() }}</span>

        {{-- Absent at zero rather than showing a nought, exactly as Updates is.
             Nothing waiting is not a status worth a badge, and a badge that is
             always there stops being read. --}}
        @if ($waiting > 0)
            <ui-badge
                class="-ms-1.5"
                text="{{ $waiting > 99 ? '99+' : $waiting }}"
                color="amber"
                size="sm"
                pill
            ></ui-badge>
        @endif
    </inertia-link>
</li>
