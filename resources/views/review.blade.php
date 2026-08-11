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
    @else
        {{-- Nothing around the frame. The panel carries its own heading and figures,
             so a second set out here only competes with them.

             The width itself is relaxed further down, outside the control panel's
             own markup. --}}
        <div class="altimiser-frame">
            <iframe
                src="{{ $embedUrl }}"
                title="Altimiser review queue"
                class="w-full block"
                style="height: calc(100vh - 5rem); border: 0;"
                {{-- The apply confirmation is a modal rendered in the page rather than a
                     native dialog, precisely because a sandboxed frame may refuse confirm()
                     outright and the one gate before writing to a client's site should not
                     depend on a sandbox token being present. allow-modals stays as belt and
                     braces for anything else that reaches for one. --}}
                sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals"
            ></iframe>
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
        .max-w-page:has(.altimiser-frame) { max-width: none; }
        .content-card:has(.altimiser-frame) { padding-left: 0; padding-right: 0; }
    </style>
@endsection
