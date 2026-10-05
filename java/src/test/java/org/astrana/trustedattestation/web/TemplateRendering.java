package org.astrana.trustedattestation.web;

import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import org.astrana.trustedattestation.contract.Attribution;
import org.astrana.trustedattestation.service.LocalizationSettings;
import org.astrana.trustedattestation.service.UiStrings;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import org.springframework.mock.web.MockServletContext;
import org.springframework.security.web.csrf.DefaultCsrfToken;
import org.thymeleaf.context.WebContext;
import org.thymeleaf.spring6.SpringTemplateEngine;
import org.thymeleaf.templatemode.TemplateMode;
import org.thymeleaf.templateresolver.ClassLoaderTemplateResolver;
import org.thymeleaf.web.servlet.JakartaServletWebApplication;

/**
 * Renders the real templates with the real strings file through the same engine the application uses, so a
 * test can read the markup a page actually produces. Shared by the tests that look at rendered pages.
 */
final class TemplateRendering {

    static final UiStrings STRINGS = new UiStrings();

    /** The anti-forgery token every rendered page is given, as Spring Security's filter would give it. */
    static final String CSRF_TOKEN = "rendered-anti-forgery-token";

    private static final SpringTemplateEngine ENGINE = engine();

    private TemplateRendering() {}

    private static SpringTemplateEngine engine() {
        ClassLoaderTemplateResolver resolver = new ClassLoaderTemplateResolver();
        resolver.setPrefix("templates/");
        resolver.setSuffix(".html");
        resolver.setTemplateMode(TemplateMode.HTML);
        resolver.setCharacterEncoding("UTF-8");

        SpringTemplateEngine engine = new SpringTemplateEngine();
        engine.setTemplateResolver(resolver);
        return engine;
    }

    /**
     * Renders a page with the model the controllers build, for a request to {@code path}, with {@code
     * overrides} replacing any of the model's entries.
     */
    static String render(
            String template,
            String path,
            LocalizationSettings localization,
            String rendered,
            Map<String, Object> overrides) {
        MockServletContext servletContext = new MockServletContext();
        MockHttpServletRequest request = new MockHttpServletRequest(servletContext, "GET", path);
        JakartaServletWebApplication application = JakartaServletWebApplication.buildApplication(servletContext);

        Map<String, Object> model = new HashMap<>();
        model.put("t", STRINGS.all(rendered));
        model.put("htmlLang", rendered);
        model.put("htmlDir", TextDirection.of(rendered));
        model.put("orgName", "Acme Bank");
        model.put("orgLogo", null);
        model.put("orgLogoDark", null);
        model.put("attribution", new Attribution());
        model.put("signedOut", false);
        model.put("memberName", "Alice");
        model.put("relationships", List.of());
        model.put("contactUrl", null);
        model.put("localization", localization);
        model.put("returnTo", LanguageSwitcher.returnTo(request));
        model.put("_csrf", new DefaultCsrfToken("X-CSRF-TOKEN", "_csrf", CSRF_TOKEN));
        model.putAll(overrides);

        WebContext context = new WebContext(
                application.buildExchange(request, new MockHttpServletResponse()),
                Locale.forLanguageTag(rendered),
                model);

        return ENGINE.process(template, context);
    }
}
