package org.astrana.trustedattestation.config;

import java.time.Clock;
import java.util.LinkedHashMap;
import java.util.Map;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.astrana.trustedattestation.service.UiStrings;
import org.astrana.trustedattestation.web.MemberLocaleResolver;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.web.servlet.LocaleResolver;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;

@Configuration
public class TrustedAttestationConfig implements WebMvcConfigurer {

    /** Injected rather than called statically, so tests can decide what "now" is. */
    @Bean
    public Clock clock() {
        return Clock.systemUTC();
    }

    /**
     * The locales this instance offers: the org's configured set intersected with the locales the app ships
     * strings for (empty config = all shipped). Shared by the request locale resolver and the language
     * switcher. Endonyms drive the default alphabetical order, so a speaker finds their language by its own
     * native name.
     */
    @Bean
    public LocalizationSettings localizationSettings(TrustedAttestationProperties properties, UiStrings strings) {
        Map<String, String> endonyms = new LinkedHashMap<>();
        strings.supportedLocales()
                .forEach(locale -> endonyms.put(locale, strings.all(locale).get("language_endonym")));

        return new LocalizationSettings(
                properties.getManifest().getSupportedLocales(),
                strings.supportedLocales(),
                properties.getManifest().getDefaultLocale(),
                endonyms);
    }

    /**
     * How the member's language is chosen from the request: the switcher's cookie, then the IAM's locale
     * claim, then the whole Accept-Language header, then the organisation's default. See {@link
     * MemberLocaleResolver} for why each step is where it is.
     */
    @Bean
    public LocaleResolver localeResolver(LocalizationSettings localization) {
        return new MemberLocaleResolver(localization);
    }
}
