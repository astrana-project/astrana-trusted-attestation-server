package org.astrana.trustedattestation.web;

import jakarta.servlet.http.HttpServletRequest;

/**
 * What the language switcher fragment ({@code language-switcher.html}) needs from the page that includes it,
 * beyond the offered locales and the rendered language the page already carries.
 */
final class LanguageSwitcher {

    private LanguageSwitcher() {}

    /**
     * The page the member is on, as the switcher's hidden {@code next} field: path and query string, so
     * choosing a language brings them back to exactly where they were. Never the scheme or host, so the
     * value is always the local path {@code /set-language} will accept.
     */
    static String returnTo(HttpServletRequest request) {
        if (request == null) {
            return "/";
        }

        String query = request.getQueryString();
        return request.getRequestURI() + (query == null || query.isEmpty() ? "" : "?" + query);
    }
}
