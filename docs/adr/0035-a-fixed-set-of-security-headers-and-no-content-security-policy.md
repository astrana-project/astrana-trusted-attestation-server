# 35. A fixed set of security headers, and no Content-Security-Policy

Accepted on 2026-10-05.

## Context

Response headers tell a browser how to treat the pages
([record 33](0033-one-accessible-interface-shared-by-all-three-implementations.md)), and the three frameworks send
different headers by default. Spring Security sends a hardening set, and ASP.NET Core and Laravel send little or none of
it. Three implementations of one contract ([record 5](0005-three-implementations-held-to-behavioural-parity.md)) should
not differ on that by accident.

## Decision

Every implementation sets the same headers explicitly on every response, the stylesheet and the other static files
included. Content-type sniffing is off, framing is denied, no referrer is sent, the legacy cross-site scripting auditor
is switched off and nothing is cached. No response from the application names its runtime, so .NET turns off Kestrel's
`Server` header and PHP removes the `X-Powered-By` header PHP adds. Under Internet Information Services (IIS), the
`web.config` that .NET and PHP each ship removes the `X-Powered-By` header IIS adds. HTTP Strict Transport Security
(HSTS) is `max-age=31536000`, one year, with no subdomains and no preload. .NET and Java send it under a setting that is
on by default, and only on a request they see as HTTPS. .NET never sends it to a request addressed to localhost,
127.0.0.1 or [::1]. Java makes no such exception, so its development profile and its demonstration turn the setting off.
PHP leaves HSTS to the web server or proxy. PHP's static files are served by the web server rather than the application,
so their headers come from the shipped `.htaccess` under Apache, the shipped `web.config` under IIS, the router script
in the container image or the operator's own web server. There is no Content-Security-Policy.

## Consequences

- A browser treats the self-service page the same whichever implementation served it. The pages cannot be framed, which
  is what a clickjacking overlay on the key field would need, and the address of a page a member was on never travels to
  another site in a referrer.
- PHP is the stack most likely to run behind someone else's server, and there the hostname's HSTS policy belongs to that
  server.
- The self-service page carries an inline script, so a Content-Security-Policy strict enough to be worth having needs a
  per-response nonce threaded through each template. A policy with `unsafe-inline` would look like protection while
  permitting exactly what the policy exists to stop. The pages therefore have no policy to fall back on if script is
  ever injected, and adding one later means moving the inline script into a file or threading a nonce through all three
  templates.
- The suite checks each header the same way in all three, and its differential part checks that the applications' own
  answers to the forwarded-scheme cases carry no `Server` or `X-Powered-By` header. HSTS is checked by the unit tests of
  .NET and Java instead, because PHP leaves it to the web server and the suite reaches every server at localhost, where
  .NET sends none.
- The HSTS value is set explicitly because the frameworks' defaults differ, 30 days with no subdomains from ASP.NET Core
  and one year with subdomains from Spring Security. One year is the baseline most hardening guidance asks for. The
  server serves nothing over plain HTTP
  ([record 31](0031-refuse-to-run-without-tls-the-identity-and-access-management-system-or-a-valid-manifest.md)), so
  committing a browser to HTTPS for the server's address for a year breaks nothing that works.
- Subdomains and preload were left out, departing from the rule that the strictest posture becomes the shared one
  ([record 5](0005-three-implementations-held-to-behavioural-parity.md)), because the application cannot see what lives
  under its own address and preload takes months to reverse. Both are the organisation's choice, made at its proxy,
  where it turns off the .NET or Java header so the browser receives one, not two.
- Behind a proxy, .NET and Java send HSTS only when the proxy sets the forwarded scheme to https, because a request with
  no forwarded scheme is served as it arrived.
- The headers are the application's. An error the connector in front of the application produces before the application
  runs, such as the refusal of an oversized header or of a malformed request line, carries none of them, on every stack,
  because Kestrel, Tomcat or the web server answers it alone. The `Server` header a web server or proxy in front adds is
  that layer's own.
- Leaving the headers to each framework was rejected because the three disagree. A policy with `unsafe-inline` was
  rejected as worse than none.
