package org.astrana.trustedattestation.config;

import org.apache.catalina.Valve;
import org.apache.catalina.core.StandardHost;
import org.apache.catalina.valves.ErrorReportValve;
import org.springframework.boot.tomcat.servlet.TomcatServletWebServerFactory;
import org.springframework.boot.web.server.WebServerFactoryCustomizer;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

/**
 * Makes Tomcat's container-level error handling answer the way the contract requires and the other two
 * implementations already do: an error is a status code and nothing else (decision record 26 in docs/adr).
 *
 * <p>{@link org.astrana.trustedattestation.web.StatusOnlyErrorController} and the endpoints themselves
 * already strip the body from every error that reaches Spring. Two things never reach Spring, though --
 * they are decided in the connector, before the servlet container runs -- and this closes both.
 */
@Configuration
public class TomcatContractConfig {

    @Bean
    public WebServerFactoryCustomizer<TomcatServletWebServerFactory> tomcatContractCustomizer() {
        return factory -> {
            // An encoded slash (%2f) in the path. Tomcat rejects it at the connector by default with a 400
            // and its own HTML error page -- both a body on an error and a status the other two never
            // produce for it: ASP.NET Core and Laravel route the undecoded path, match nothing, and answer
            // a bare 404. PASSTHROUGH leaves the %2f literal (it is never turned into a real path separator,
            // so no path traversal becomes possible) and lets the request reach the filter chain, where
            // SecurityConfig#unknownApiPathFilter answers it with the same empty-bodied 404 the others do.
            factory.addConnectorCustomizers(connector -> connector.setEncodedSolidusHandling("passthrough"));

            // Any error Tomcat still raises in the connector itself -- a malformed request line, an
            // oversized header -- is served by the host's ErrorReportValve, which writes an HTML report and
            // a server banner. Silence both so what remains is the status code alone, matching the other
            // two. Best-effort: if the valve is not on the pipeline yet when this runs, there is simply
            // nothing to quiet, and the encoded-slash case above is handled regardless.
            factory.addContextCustomizers(context -> {
                if (context.getParent() instanceof StandardHost host) {
                    for (Valve valve : host.getPipeline().getValves()) {
                        if (valve instanceof ErrorReportValve report) {
                            report.setShowReport(false);
                            report.setShowServerInfo(false);
                        }
                    }
                }
            });
        };
    }
}
