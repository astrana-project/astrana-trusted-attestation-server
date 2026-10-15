package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import jakarta.servlet.http.HttpServlet;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.util.stream.Collectors;
import java.util.stream.IntStream;
import org.junit.jupiter.api.Test;
import org.springframework.boot.autoconfigure.AutoConfigurations;
import org.springframework.boot.test.context.runner.WebApplicationContextRunner;
import org.springframework.boot.tomcat.autoconfigure.servlet.TomcatServletWebServerAutoConfiguration;
import org.springframework.boot.web.server.servlet.context.AnnotationConfigServletWebServerApplicationContext;
import org.springframework.boot.web.server.servlet.context.ServletWebServerApplicationContext;
import org.springframework.boot.web.servlet.ServletRegistrationBean;

/**
 * A request whose Accept-Language lists many languages is answered, as on the other two implementations.
 *
 * <p>Spring's {@code RequestContextFilter} asks for the request's locale on every request, and Tomcat refuses to
 * parse more locales than the connector's {@code maxLocaleCount}, by throwing. This test starts a real Tomcat
 * through Boot's own auto-configuration, with this application's connector customisation, and serves a page that
 * asks for the locale the same way.
 */
class ManyLocalesTest {

    private final WebApplicationContextRunner contexts = new WebApplicationContextRunner(
                    AnnotationConfigServletWebServerApplicationContext::new)
            .withConfiguration(AutoConfigurations.of(TomcatServletWebServerAutoConfiguration.class))
            .withUserConfiguration(TomcatContractConfig.class)
            .withBean("page", ServletRegistrationBean.class, () -> new ServletRegistrationBean<>(new Page(), "/page"))
            .withPropertyValues("server.port=0");

    @Test
    void aRequestListingTwoHundredLanguagesIsAnswered() {
        String languages = IntStream.range(0, 200)
                .mapToObj(i -> "l" + i + ";q=0." + i % 10)
                .collect(Collectors.joining(","));

        contexts.run(context -> {
            int port = context.getSourceApplicationContext(ServletWebServerApplicationContext.class)
                    .getWebServer()
                    .getPort();

            try (HttpClient client = HttpClient.newHttpClient()) {
                HttpResponse<Void> response = client.send(
                        HttpRequest.newBuilder(URI.create("http://localhost:" + port + "/page"))
                                .header("Accept-Language", languages)
                                .build(),
                        HttpResponse.BodyHandlers.discarding());

                assertThat(response.statusCode()).isEqualTo(200);
            }
        });
    }

    /** A page that reads the request's locale, as Spring's RequestContextFilter does, then answers 200. */
    private static final class Page extends HttpServlet {

        @Override
        protected void doGet(HttpServletRequest request, HttpServletResponse response) {
            request.getLocale();
            response.setStatus(HttpServletResponse.SC_OK);
        }
    }
}
