{{-- The language switcher: a compact disclosure in the top-right showing a translate icon and the current
     language. Opening it lists the languages the organisation offers (decision record 34 in docs/adr). Each choice
     posts to /set-language, which stores it in a validated cookie and returns here. Native <details>, so it
     works with no JavaScript. Shown only when the organisation offers more than one locale. The markup is
     the .NET partial's, so the rendered page is the same whichever stack served it. --}}
@php
    $currentLanguage = str_contains($htmlLang, '-') ? substr($htmlLang, 0, strpos($htmlLang, '-')) : $htmlLang;
    $isActive = fn (string $locale): bool => strcasecmp($locale, $htmlLang) === 0 || strcasecmp($locale, $currentLanguage) === 0;
    $active = array_values(array_filter($localization->locales(), $isActive))[0] ?? $localization->locales()[0];
@endphp
@if ($localization->showSwitcher())
    <details class="ata-language">
        <summary>
            {{-- The translate glyph (inlined, currentColor, no icon font or external request). Bootstrap
                 Icons "translate", MIT-licensed. Decorative: the visible language name carries the meaning. --}}
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M4.545 6.714 4.11 8H3l1.862-5h1.284L8 8H6.833l-.435-1.286zm1.634-.736L5.5 3.956h-.049l-.679 2.022z" />
                <path d="M0 2a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v3h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-3H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zm7.138 9.995q.289.451.63.846c-.748.575-1.673 1.001-2.768 1.292.178.217.451.635.555.867 1.125-.359 2.08-.844 2.886-1.494.777.665 1.739 1.165 2.93 1.472.133-.254.414-.673.629-.89-1.125-.253-2.057-.694-2.82-1.284.681-.747 1.222-1.651 1.621-2.757H14V8h-3v1.047h.765c-.318.844-.74 1.546-1.272 2.13a6 6 0 0 1-.415-.492 2 2 0 0 1-.94.31" />
            </svg>
            <span class="visually-hidden">{{ $t['language_label'] }}: </span>
            <span>{{ $strings->get('language_endonym', $active) }}</span>
        </summary>
        <form method="post" action="/set-language" class="ata-language-menu">
            <input type="hidden" name="next" value="{{ $returnTo }}" />
            @foreach ($localization->locales() as $locale)
                <button type="submit" name="locale" value="{{ $locale }}"
                        class="btn btn-sm btn-link text-start p-1{{ $isActive($locale) ? ' fw-bold' : '' }}"{!! $isActive($locale) ? ' aria-current="true"' : '' !!}>{{ $strings->get('language_endonym', $locale) }}</button>
            @endforeach
        </form>
    </details>
@endif
