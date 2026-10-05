# 26. Error responses reveal nothing beyond the status code

Accepted on 2026-10-05.

## Context

Left to itself, each framework answers some errors with a default body of its own, and each body is different. Tomcat
and Laravel send an HTML page, and ASP.NET Core in development sends a detailed exception page. These often name the
framework and its version.

## Decision

An error response is a status code with the headers every response carries, and no body and no `Content-Type`. A path no
implementation routes, under the API or not, answers HTTP 404 - Not Found before authentication, so that a caller can
tell a path that does not exist from one that needs a sign-in. The rule holds in every environment, because the
application's own handler sets the status rather than relying on a production mode to hide a page.

## Consequences

- A consumer relies on the status alone, identically across the three, and an attacker learns nothing about what is
  behind it.
- Integrators get no diagnostic body, which is acceptable because the API is small and every status a conforming
  consumer meets is specified.
- PHP has to strip the `Content-Type` its runtime stamps on empty bodies, and Java has to replace the container's error
  page and answer unknown paths ahead of its security chain, which would otherwise redirect an unknown page path to
  sign-in. The differential part of the suite compares status, content type and body on every API request it sends.
- Problem details as defined in Request for Comments (RFC) 9457 were rejected because body parity across three
  frameworks is harder and bodies leak internals. The frameworks' default responses were rejected for the same reason.
