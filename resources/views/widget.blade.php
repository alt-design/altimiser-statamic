<div class="card p-4 h-full flex flex-col">
    <div class="flex items-baseline justify-between mb-3">
        <h2 class="font-medium">SEO Recommendations</h2>
        @if ($summary)
            <span class="text-xs text-gray-600">{{ $summary['outstanding'] }} open</span>
        @endif
    </div>

    @unless ($connected)
        <p class="text-sm text-gray-600">
            Altimiser is not configured on this site. Set <code>ALTIMISER_URL</code> and
            <code>ALTIMISER_SECRET</code> in your environment.
        </p>
    @else
        @if ($summary)
            <p class="text-sm text-gray-600 mb-4">
                {{ $summary['handled'] }} this site can act on.
                @if ($summary['pending'])
                    <strong>{{ $summary['pending'] }} awaiting apply.</strong>
                @endif
            </p>
        @else
            <p class="text-sm text-gray-600 mb-4">
                Counts are unavailable right now, but the review queue should still open.
            </p>
        @endif

        <div class="mt-auto">
            <a href="{{ $review_url }}" target="_blank" rel="noopener" class="btn-primary">
                Review changes
            </a>
        </div>
    @endunless
</div>
