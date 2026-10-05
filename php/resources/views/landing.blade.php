<!DOCTYPE html>
{{-- lang and dir come from the locale the page is actually rendered in (UiStrings::resolve), not the
     raw request locale: a request for an untranslated language is served in English and must not
     advertise itself -- nor mirror the layout -- as that language. --}}
<html lang="{{ $htmlLang }}" dir="{{ $htmlDir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The landing page is deliberately indexable: it is what enrollment_url points at, and a
         prospective member finding it through search is the point. Only /me is kept out of indexes. --}}
    <title>{{ $orgName }} | {{ $t['landing_title'] }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ url('/favicon.svg') }}">
    <link rel="stylesheet" href="{{ url('/trusted-attestation.css') }}">
    {{-- The organisation's own colours and fonts, editable without a rebuild. Loaded after the main
         stylesheet so its custom properties win. --}}
    <link rel="stylesheet" href="{{ url('/theme-overrides.css') }}">
</head>
<body class="py-4 px-3">
<div class="ata-topbar mb-3">
    @include('shared.language-switcher')
</div>
<main class="card ata-page mx-auto p-4">
    <div class="d-flex align-items-center gap-3 mb-4 ata-brand">
        {{-- Exactly one h1, always. With a logo the image carries the name and the heading is kept for
             document structure and assistive technology; without one the shipped generic mark is
             decorative and the name shows as the heading, so the page still looks finished. --}}
        @if ($orgLogo)
            <picture>
                @if ($orgLogoDark)
                    <source srcset="{{ $orgLogoDark }}" media="(prefers-color-scheme: dark)">
                @endif
                <img class="ata-brand-logo" src="{{ $orgLogo }}" alt="" aria-hidden="true">
            </picture>
            <h1 class="fs-5 mb-0 visually-hidden">{{ $orgName }}</h1>
        @else
            <img class="ata-brand-logo" src="{{ url('/favicon.svg') }}" alt="" aria-hidden="true">
            <h1 class="fs-5 mb-0">{{ $orgName }}</h1>
        @endif
    </div>

    <p class="ata-field-label text-body-secondary mb-3">{{ $t['landing_title'] }}</p>

    @if ($signedOut)
        <p class="ata-status text-success" role="status">{{ $t['signed_out'] }}</p>
    @endif

    <p class="mb-4">{{ str_replace('{org}', $orgName, $t['landing_intro']) }}</p>

    <div class="d-flex flex-wrap gap-3">
        <a class="btn btn-primary" href="{{ url('/me') }}">{{ $t['landing_sign_in'] }}</a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ $attribution->projectUrl }}"
           rel="noopener" target="_blank">{{ $t['landing_learn_more'] }}</a>
    </div>
</main>

{{-- The software's own attribution, which the server has no setting to remove. The
     licence link's label is the one string here that is translated, from the shared strings file, so the
     footer reads in the member's language like the rest of the page. --}}
<footer class="ata-page ata-attribution mx-auto mt-4 text-body-secondary">
    <a href="{{ $attribution->projectUrl }}" rel="noopener" target="_blank">{{ $attribution->projectName }}</a>
    @if ($attribution->licenseNotice)
        &middot;
        @if ($attribution->licenseUrl)
            <a href="{{ $attribution->licenseUrl }}" rel="noopener" target="_blank">{{ $t['licence_link'] }}</a>
        @else
            <span>{{ $t['licence_link'] }}</span>
        @endif
    @endif
</footer>
</body>
</html>
