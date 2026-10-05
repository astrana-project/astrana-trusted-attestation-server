<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contract\Attribution;
use App\Contract\RelationshipTypeCatalog;
use App\Models\MemberRelationship;
use App\Security\SafeReturnPath;
use App\Services\LocalizationSettings;
use App\Services\ManifestBuilder;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipService;
use App\Services\UiStrings;
use App\Support\PublicKeys;
use App\Support\TextDirection;
use App\Support\Timestamps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\AcceptHeaderItem;

final class PageController extends Controller
{
    /** The language switcher's cookie: a plain locale tag, set only by POST /set-language. */
    public const LOCALE_COOKIE = 'ata_locale';

    public function __construct(
        private readonly MemberIdentityResolver $resolver,
        private readonly RelationshipService $relationships,
        private readonly RelationshipTypeCatalog $catalog,
        private readonly UiStrings $strings,
        private readonly ManifestBuilder $manifests,
        private readonly Attribution $attribution,
        private readonly LocalizationSettings $localization,
    ) {}

    /**
     * The org's own name and logo, plus the fixed attribution, on every render of the page.
     *
     * Branding comes from the manifest -- the same content the org already maintains for discovery --
     * rather than from a separate branding setting. The attribution comes from the contract and is not
     * org-configurable at all.
     *
     * Also what the language switcher needs: the offered locales, the strings to name each one by its own
     * endonym, and the page to return to after a choice.
     *
     * @return array<string, mixed>
     */
    private function chrome(Request $request, string $locale): array
    {
        $manifest = $this->manifests->build();
        $defaultLocale = is_string($manifest['default_locale'] ?? null) ? $manifest['default_locale'] : 'en';

        $names = is_array($manifest['name'] ?? null) ? $manifest['name'] : [];
        $orgName = self::localizedFromManifest($names, $locale, $defaultLocale);

        // Where a member who holds no relationship yet is pointed for help: the manifest's support URL if
        // set, otherwise its website, otherwise null (the empty state then names the org as plain text).
        // Resolved for the member's locale, the same way the org name is.
        $support = is_array($manifest['support_url'] ?? null) ? $manifest['support_url'] : [];
        $website = is_array($manifest['website'] ?? null) ? $manifest['website'] : [];
        $supportUrl = self::localizedFromManifest($support, $locale, $defaultLocale);
        $websiteUrl = self::localizedFromManifest($website, $locale, $defaultLocale);
        $contactUrl = $supportUrl !== '' ? $supportUrl : $websiteUrl;
        if ($contactUrl === '') {
            $contactUrl = null;
        }

        // lang and dir describe the language the page is actually rendered in -- the resolved locale --
        // never Laravel's app locale, which this application leaves at its default and so would label
        // every page "en" regardless of the text on it. dir keys on the same explicit right-to-left set the
        // other two implementations use (see TextDirection), so all three flip together.
        $rendered = $this->strings->resolve($locale);

        return [
            'orgName' => $orgName,
            'contactUrl' => $contactUrl,
            'orgLogo' => $manifest['logo_data'] ?? null,
            'orgLogoDark' => $manifest['logo_data_dark'] ?? null,
            'attribution' => $this->attribution,
            'htmlLang' => $rendered,
            'htmlDir' => TextDirection::of($rendered),
            'localization' => $this->localization,
            'strings' => $this->strings,
            'returnTo' => $request->getRequestUri(),
        ];
    }

    /**
     * Which language to render in, decided in a fixed order so the same request gives the same page on all
     * three implementations (decision record 34 in docs/adr).
     *
     * First the language switcher's own cookie: a member who picked a language has said what they want. It
     * is a plain cookie holding just the locale tag, validated on write to the offered set and constrained
     * again here, so an unknown or no-longer-offered value is ignored and resolution falls through. Then an
     * IAM-provided locale claim, because the organisation knows which language it holds this member's record
     * in. Then the browser's Accept-Language, the whole ordered list, each entry matched by the same rule
     * as the cookie and the claim (a region such as fr-CA falling back to its language, zh-TW to zh-Hant).
     * Then the organisation's default locale. No query parameter sets the locale, and no cookie other than
     * the switcher's own.
     *
     * @param  array<string, mixed>  $claims  the session's verified claims, empty on an anonymous page
     */
    private function resolveLocale(Request $request, array $claims = []): string
    {
        $cookie = $request->cookies->get(self::LOCALE_COOKIE);
        $claim = $claims[MemberIdentityResolver::LOCALE_CLAIM] ?? null;

        // In the order above. The first candidate that matches an offered locale wins.
        $candidates = [
            is_string($cookie) ? $cookie : null,
            is_string($claim) ? $claim : null,
            ...self::acceptedLanguages($request),
        ];

        foreach ($candidates as $candidate) {
            $matched = $this->localization->match($candidate);
            if ($matched !== null) {
                return $matched;
            }
        }

        return $this->localization->defaultLocale();
    }

    /**
     * The browser's Accept-Language entries in order of preference, leaving out any entry with q=0, which
     * the browser sends to mean "not this language", as the other two implementations leave it out.
     *
     * Each entry is matched by the caller rather than left to Symfony's getPreferredLanguage(), which pairs
     * a tag with an offered locale by language prefix before the script rule can see it and so would send
     * zh-TW to zh-Hans.
     *
     * @return list<string>
     */
    private static function acceptedLanguages(Request $request): array
    {
        $entries = AcceptHeader::fromString($request->headers->get('Accept-Language'))->all();
        $wanted = array_filter($entries, static fn (AcceptHeaderItem $entry): bool => $entry->getQuality() > 0);

        return array_values(array_map(static fn (AcceptHeaderItem $entry): string => $entry->getValue(), $wanted));
    }

    /**
     * The verified claims the session holds, empty when nobody is signed in.
     *
     * @return array<string, mixed>
     */
    private static function sessionClaims(Request $request): array
    {
        $claims = $request->session()->get(MemberIdentityResolver::SESSION_KEY, []);

        return is_array($claims) ? $claims : [];
    }

    /**
     * Picks a locale-keyed manifest value, falling back the way decision record 34 says a consumer should:
     * the requested locale, then its bare language, then the manifest's own declared default. Returns the
     * empty string when nothing matches, so callers can treat "" as "not set".
     *
     * @param  array<string, mixed>  $map
     */
    private static function localizedFromManifest(array $map, string $locale, string $defaultLocale): string
    {
        foreach (RelationshipTypeCatalog::localeFallbacks($locale) as $candidate) {
            if (isset($map[$candidate]) && is_string($map[$candidate])) {
                return $map[$candidate];
            }
        }

        $fallback = $map[$defaultLocale] ?? '';

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * The public landing page: what this instance is, whose it is, and where to sign in.
     *
     * Deliberately unauthenticated and deliberately indexable -- this is the page the manifest's
     * enrollment_url points at, so it is the first thing a prospective member sees, before they have
     * any session to show. It makes no API calls and reveals nothing member-specific: everything on it
     * is already public through the manifest.
     *
     * $signedOut renders the post-logout confirmation. It arrives via the dedicated /signed-out route
     * rather than a query marker, because the IdP matches its registered post-logout URIs exactly and
     * one clean path is the only registration an operator can be expected to make.
     */
    public function landing(Request $request, bool $signedOut = false): View
    {
        // A signed-in member's locale claim applies here as it does on /me, so the two pages agree.
        $locale = $this->resolveLocale($request, self::sessionClaims($request));

        return view('landing', $this->chrome($request, $locale) + [
            't' => $this->strings->all($locale),
            'signedOut' => $signedOut,
        ]);
    }

    /**
     * The software's own licence, at the fixed public path the footer links to (see attribution.json).
     *
     * Anonymous, like the landing page, because a licence is public. Its content is the attribution every
     * page already carries, the project name, the MIT License text and the notices from the contract's
     * attribution.json, so there is one source and nothing here to keep in step by hand, plus a link to
     * the third-party notices that scripts/generate-third-party-notices.php writes into public/. English and
     * left-to-right, with no organisation branding and no language switcher, because this is a statement
     * about the software, not about the organisation running the instance. It sets no cookie.
     */
    public function license(): View
    {
        return view('license', ['attribution' => $this->attribution]);
    }

    /**
     * The language switcher's target. Stores the chosen locale in a plain cookie, validated against the
     * offered set so a forged value cannot render an unsupported language, and returns to the page the
     * member was on.
     *
     * No CSRF token (excluded in bootstrap/app.php), because changing one's own display language is
     * harmless, and the switcher is on the public page too, which carries no session to hold a token.
     * A cross-site form can still submit the switcher, and the most it changes is the display language,
     * which is limited to the offered set. The return path is checked the same way the post-login
     * redirect is: a path on this origin or the landing page, never anywhere else.
     * Kept identical across the three stacks.
     *
     * Only the posted form is read, never the query string and never a JSON body, as on the other two
     * implementations. A request with no form, or a form with no usable locale, is the same redirect with
     * no cookie. The content type is checked first, because Laravel fills the request bag from a JSON
     * body too. The raw form array is read rather than the input bag, whose get() throws on a value
     * posted as an array, and that would be an error page for a request that only deserves the redirect.
     */
    public function setLanguage(Request $request): RedirectResponse
    {
        $form = $request->getContentTypeFormat() === 'form' ? $request->request->all() : [];
        $locale = $form['locale'] ?? null;
        $next = SafeReturnPath::sanitize($form['next'] ?? null, '/');

        // Relative, as the other two implementations answer, rather than Laravel's absolute URL.
        $response = new RedirectResponse($next);

        $offered = $this->localization->offered(is_string($locale) ? $locale : null);
        if ($offered !== null) {
            // The shipped spelling, not the posted one, as the other two implementations store it. One
            // year, HttpOnly, Secure, SameSite=Lax, on the whole site. Not encrypted (see
            // bootstrap/app.php), because the bare tag is what every implementation reads.
            $response->withCookie(cookie(self::LOCALE_COOKIE, $offered, 60 * 24 * 365, '/', null, true, true, false, 'lax'));
        }

        return $response;
    }

    /**
     * The self-service page, the only page that needs a signed-in member. The landing page and the licence
     * page are public.
     *
     * The signed-in member sees their own name, so they know they are signed in as themselves, and every
     * relationship they hold, each with its own key and its own controls. There is no administrator or
     * staff interface anywhere in Astrana Trusted Attestation. Standing is changed through the
     * organisation's stored procedures instead, so there is no extra sign-in path and no extra endpoint
     * for an attacker to target.
     *
     * Each relationship is listed separately rather than merged into one summary, because they really
     * are separate: independent keys, independent standing, independently removable. A page that showed
     * a single combined state would be describing something the data model does not have.
     */
    public function me(Request $request): View|RedirectResponse
    {
        $member = $this->resolver->resolve($request);

        if ($member === null) {
            // A session that holds claims but no usable subject was signed in as nobody. Sent to sign in
            // again it would come straight back in the same state, without end, so it is ended here and
            // the member sees the landing page, from which they can sign in afresh. The callbacks no
            // longer establish such a session, so this is for one that already exists.
            if ($request->session()->has(MemberIdentityResolver::SESSION_KEY)) {
                $request->session()->flush();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $this->landing($request);
            }

            return redirect('/auth/login?next=/me');
        }

        // Rendering in the member's language is a requirement, not optional polish. The switcher's own
        // choice, then the IAM-provided locale claim, then Accept-Language: see resolveLocale().
        $locale = $this->resolveLocale($request, self::sessionClaims($request));

        $now = Carbon::now();

        // An empty list is an ordinary state, not an error: it is what a member looks like before the
        // organisation has granted them anything, and the page has to render for them too.
        $relationships = $this->relationships->findAllHeldBy($member->iamSubjectId)
            ->map(fn (MemberRelationship $row): array => [
                'relationshipType' => $row->relationship_type,
                'label' => $this->catalog->label($row->relationship_type, $locale),
                'subtype' => $row->relationship_subtype,
                'status' => $row->statusAt($now)->value,
                'publicKey' => $row->public_key === null ? null : PublicKeys::toBase64($row->public_key),
                // The same string the API returns for this row (see Timestamps).
                'expiresAt' => $row->expires_at === null ? null : Timestamps::iso8601Utc($row->expires_at),
            ])
            ->values()
            ->all();

        return view('me', $this->chrome($request, $locale) + [
            't' => $this->strings->all($locale),
            'memberName' => $member->name,
            'relationships' => $relationships,
        ]);
    }

    /**
     * A single, permanently stable path -- never versioned. That is the point of a well-known discovery
     * document: a client never has to guess a version to find it. The manifest_version field inside is
     * what tells a consumer how to parse the content.
     *
     * Public, no auth: it describes the organisation, not any member, and a prospective member has to be
     * able to read it before they have an account.
     */
    public function manifest(ManifestBuilder $manifests): JsonResponse
    {
        return response()->json($manifests->build());
    }
}
