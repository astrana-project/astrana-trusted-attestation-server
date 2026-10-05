<!DOCTYPE html>
{{-- The licence page is the software's own, so its content is English and its direction fixed: it renders
     the attribution's project name, licence and trademark notices, which are not locale-keyed. No
     organisation branding and no language switcher. This is a statement about the software, not about the
     organisation running it. --}}
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $attribution->projectName }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ url('/favicon.svg') }}">
    <link rel="stylesheet" href="{{ url('/trusted-attestation.css') }}">
    {{-- The organisation's own colours and fonts, editable without a rebuild. Loaded after the main
         stylesheet so its custom properties win. --}}
    <link rel="stylesheet" href="{{ url('/theme-overrides.css') }}">
</head>
<body class="py-4 px-3">
<main class="card ata-page mx-auto p-4">
    <h1 class="fs-5 mb-3">{{ $attribution->projectName }}</h1>
    {{-- Everything below comes from the contract's attribution.json, so all three implementations say the
         same thing and changing it is one edit. --}}
    <p>{{ $attribution->licenseNotice }}</p>
    @foreach ($attribution->licenseText as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach
    <p>{{ $attribution->trademarkNotice }}</p>
    {{-- Not from attribution.json: scripts/generate-third-party-notices.php writes the notices into public/
         from this implementation's own packages, after every Composer install and in the container image. --}}
    <p>
        This software includes third-party components under their own licences, listed with their copyright notices
        and licence texts in <a href="{{ url('/THIRD-PARTY-NOTICES.txt') }}">THIRD-PARTY-NOTICES.txt</a>.
    </p>
    <p class="mb-0">
        Source code and documentation:
        <a href="{{ $attribution->sourceUrl }}" rel="noopener" target="_blank">{{ str_replace('https://', '', $attribution->sourceUrl) }}</a>.
        Project:
        <a href="{{ $attribution->projectUrl }}" rel="noopener" target="_blank">{{ str_replace('https://', '', $attribution->projectUrl) }}</a>.
    </p>
</main>

{{-- The software's own attribution, which the server has no setting to remove. --}}
<footer class="ata-page ata-attribution mx-auto mt-4 text-body-secondary">
    <a href="{{ $attribution->projectUrl }}" rel="noopener" target="_blank">{{ $attribution->projectName }}</a>
    @if ($attribution->licenseNotice)
        &middot;
        @if ($attribution->licenseUrl)
            <a href="{{ $attribution->licenseUrl }}" rel="noopener" target="_blank">{{ $attribution->licenseLabel }}</a>
        @else
            <span>{{ $attribution->licenseLabel }}</span>
        @endif
    @endif
</footer>
</body>
</html>
