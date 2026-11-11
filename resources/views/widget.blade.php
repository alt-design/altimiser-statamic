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
        @if (! $linked)
            <p class="text-sm text-gray-600 mb-4">
                Connect your Altimiser account to see this site's recommendations.
            </p>
        @elseif ($summary)
            <p class="text-sm text-gray-600 mb-4">
                {{ $summary['handled'] }} this site can act on.
                @if ($summary['pending'])
                    <strong>{{ $summary['pending'] }} awaiting apply.</strong>
                @endif
            </p>
        @else
            <p class="text-sm text-gray-600 mb-4">
                Counts are unavailable right now.
            </p>
        @endif

        <div class="mt-auto">
            <ui-button href="{{ $queue_url }}" variant="primary" text="{{ $linked ? 'Review changes' : 'Connect' }}"></ui-button>
        </div>
    @endunless
</div>
