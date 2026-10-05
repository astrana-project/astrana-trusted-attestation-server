package org.astrana.trustedattestation.web;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockHttpServletRequest;

/** The hidden return path the switcher posts, which has to bring the member back to exactly where they were. */
class LanguageSwitcherTest {

    @Test
    void theReturnPathIsThePathAndQueryStringNeverTheHost() {
        MockHttpServletRequest request = new MockHttpServletRequest("GET", "/me");
        assertThat(LanguageSwitcher.returnTo(request)).isEqualTo("/me");

        request.setQueryString("signedout=true");
        assertThat(LanguageSwitcher.returnTo(request)).isEqualTo("/me?signedout=true");
    }

    @Test
    void noRequestMeansTheLandingPage() {
        assertThat(LanguageSwitcher.returnTo(null)).isEqualTo("/");
    }
}
