package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import jakarta.servlet.http.HttpServletRequest;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockFilterChain;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;

/**
 * SAMLRequest is a parameter only at the single logout address. Anywhere else the filter answers null for
 * it without asking the container, which under Tomcat would parse, and so use up, a form post's body.
 */
class SamlRequestParameterScopeTest {

    @Test
    void atTheSingleLogoutAddressTheRequestIsPassedOnAsItIs() throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/logout/saml2/slo");
        request.setParameter("SAMLRequest", "request");

        HttpServletRequest passed = filtered(request);

        assertThat(passed).isSameAs(request);
        assertThat(passed.getParameter("SAMLRequest")).isEqualTo("request");
    }

    @Test
    void elsewhereSamlRequestIsAbsentAndOtherParametersAreUntouched() throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/set-language");
        request.setParameter("SAMLRequest", "request");
        request.setParameter("locale", "fr");

        HttpServletRequest passed = filtered(request);

        assertThat(passed.getParameter("SAMLRequest")).isNull();
        assertThat(passed.getParameter("locale")).isEqualTo("fr");
    }

    private static HttpServletRequest filtered(MockHttpServletRequest request) throws Exception {
        MockFilterChain chain = new MockFilterChain();
        new SamlRequestParameterScope().doFilter(request, new MockHttpServletResponse(), chain);
        return (HttpServletRequest) chain.getRequest();
    }
}
