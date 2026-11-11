@extends('statamic::layout')
@section('title', 'Altimiser')

@section('content')
    @unless ($connected)
        <div class="card p-4">
            <p class="text-gray-700">
                Altimiser is not configured on this site. Set <code>ALTIMISER_URL</code> and
                <code>ALTIMISER_SECRET</code> in your environment, then connect the site from
                Altimiser with <code>altimiser:connect</code>.
            </p>
        </div>
    @elseif ($embedUrl === null)
        <div class="card p-6 max-w-lg mx-auto text-center">
            <h2 class="font-medium text-lg mb-2">Connect your Altimiser account</h2>
            <p class="text-gray-700 mb-4">
                The review queue is on Altimiser. Connect your Altimiser account to use it.
                Your agency gives you an account.
            </p>
            <a href="{{ cp_route('altimiser.connect') }}" target="_top" class="btn-primary">Connect</a>
        </div>
    @else
        {{-- Nothing around the frame. The panel carries its own heading and figures,
             so a second set out here only competes with them.

             The width itself is relaxed further down, outside the control panel's
             own markup. --}}
        <div class="altimiser-layout">
        <div class="altimiser-connection">
            Connected as {{ $accountEmail }} until {{ $connectionExpiresAt->format('j M Y, H:i') }}.
            <form method="POST" action="{{ cp_route('altimiser.disconnect') }}">
                @csrf
                <button type="submit">Disconnect</button>
            </form>
        </div>

        <div class="altimiser-frame">
            <iframe
                src="{{ $embedUrl }}"
                title="Altimiser review queue"
                class="altimiser-iframe"
                {{-- The apply confirmation is a modal rendered in the page rather than a
                     native dialog, precisely because a sandboxed frame may refuse confirm()
                     outright and the one gate before writing to a client's site should not
                     depend on a sandbox token being present. allow-modals stays as belt and
                     braces for anything else that reaches for one. --}}
                sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals"
            ></iframe>
        </div>
        </div>
    @endunless
@endsection

{{-- The control panel centres its content on a fixed maximum width and pads it,
     which on a large screen leaves this panel in a narrow column with most of the
     display unused. It is a dense screen and it wants the room.

     This has to live in the scripts section: anything in the content section is
     handed to the control panel's own front end, which drops style tags. --}}
@section('scripts')
    <style>
        .altimiser-layout { display: flex; flex-direction: column; height: calc(100vh - 5rem); }
        .altimiser-connection { flex: 0 0 auto; padding: 0.5rem; font-size: 0.875rem; color: #4b5563; }
        .altimiser-connection form { display: inline; }
        .altimiser-connection a, .altimiser-connection button { text-decoration: underline; }
        .altimiser-frame { flex: 1 1 auto; min-height: 0; }
        .altimiser-iframe { display: block; width: 100%; height: 100%; border: 0; }
        .max-w-page:has(.altimiser-frame) { max-width: none; }
        .content-card:has(.altimiser-frame) { padding-left: 0; padding-right: 0; }
    </style>
@endsection
