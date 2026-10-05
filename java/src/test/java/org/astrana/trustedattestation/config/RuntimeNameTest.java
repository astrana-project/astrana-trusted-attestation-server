package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import jakarta.servlet.http.HttpServlet;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.util.List;
import org.junit.jupiter.api.Test;
import org.springframework.boot.autoconfigure.AutoConfigurations;
import org.springframework.boot.test.context.runner.WebApplicationContextRunner;
import org.springframework.boot.tomcat.autoconfigure.servlet.TomcatServletWebServerAutoConfiguration;
import org.springframework.boot.web.server.servlet.context.AnnotationConfigServletWebServerApplicationContext;
import org.springframework.boot.web.server.servlet.context.ServletWebServerApplicationContext;
import org.springframework.boot.web.servlet.ServletRegistrationBean;

/**
 * No response names the runtime that answered it, as on the other two implementations, so a verifying
 * instance cannot tell the stacks apart by a {@code Server} or {@code X-Powered-By} header.
 *
 * <p>Tomcat sends a {@code Server} header only when one is configured, which under Spring Boot is the
 * {@code server.server-header} setting, and sends {@code X-Powered-By} only when asked to. Neither is set
 * here, and {@code DefaultConfigurationTest} pins that the packaged defaults leave the setting out. This
 * test starts a real Tomcat through Boot's own auto-configuration, with this application's connector
 * customisation, and reads the headers off the wire, for a served page and for a path nothing serves.
 */
class RuntimeNameTest {

    private final WebApplicationContextRunner contexts = new WebApplicationContextRunner(
                    AnnotationConfigServletWebServerApplicationContext::new)
            .withConfiguration(AutoConfigurations.of(TomcatServletWebServerAutoConfiguration.class))
            .withUserConfiguration(TomcatContractConfig.class)
            .withBean("page", ServletRegistrationBean.class, () -> new ServletRegistrationBean<>(new Page(), "/page"))
            .withPropertyValues("server.port=0");

    @Test
    void noResponseCarriesAServerOrXPoweredByHeader() {
        contexts.run(context -> {
            int port = context.getSourceApplicationContext(ServletWebServerApplicationContext.class)
                    .getWebServer()
                    .getPort();

            HttpResponse<Void> served = get(port, "/page");
            HttpResponse<Void> missing = get(port, "/nothing-here");

            assertThat(served.statusCode()).isEqualTo(200);
            assertThat(missing.statusCode()).isEqualTo(404);
            for (HttpResponse<Void> response : List.of(served, missing)) {
                assertThat(response.headers().firstValue("Server")).isEmpty();
                assertThat(response.headers().firstValue("X-Powered-By")).isEmpty();
            }
        });
    }

    private static HttpResponse<Void> get(int port, String path) throws Exception {
        try (HttpClient client = HttpClient.newHttpClient()) {
            return client.send(
                    HttpRequest.newBuilder(URI.create("http://localhost:" + port + path))
                            .build(),
                    HttpResponse.BodyHandlers.discarding());
        }
    }

    /** A page that answers 200 with nothing, so the only headers are the ones the server adds. */
    private static final class Page extends HttpServlet {

        @Override
        protected void doGet(HttpServletRequest request, HttpServletResponse response) {
            response.setStatus(HttpServletResponse.SC_OK);
        }
    }
}
