# Installing the Java implementation

The Java implementation is for any organisation that prefers Java. It runs on Spring Boot with its built-in web server,
Tomcat, on a Java 25 runtime.

You can install it as a standalone jar run as a service, or run it as the Docker container image `astrana/ata-java`,
which listens on port 8080 inside the container. It is well suited to and aligned with enterprises and government
departments. They usually have a central identity and access management system, a database team and change control, and
the steps below are written for that setting.

**Installation has five steps:**

1. Register the server with your identity system.
2. Create an empty database.
3. Write the configuration.
4. Run the server and confirm it works.
5. Grant, revoke and extend relationships from your own systems.

The server creates its own schema on first run, or your database team applies it. There is no administrator interface.
After installation, your own systems of record grant, revoke and extend relationships by calling procedures in the
database.

## Container or server

You can run the server in one of two ways. Only Step 4, running it, differs between them. Choose the one that fits how
your organisation already runs services.

- **As a Docker container.** You pull the published image and run it behind a reverse proxy that holds the certificate.
  This suits an organisation that already runs containers, under Docker, Kubernetes or a cloud container service,
  because nothing is installed on the host and an update is a new image.
- **Installed on a server.** You build the jar from this repository and run it as a service on a server with a Java 25
  runtime, behind the reverse proxy your other services use. This suits an organisation that runs Java applications on
  servers it manages, or whose change control approves software installed on hosts rather than container images.

## Before you start

You need four things:

- An identity and access management system that supports OpenID Connect or SAML 2.0 and already knows every person you
  intend to grant a relationship to. Keycloak, Microsoft Entra ID, Active Directory Federation Services, Okta,
  PingFederate and the other enterprise systems all qualify. This page calls it the identity system. The
  [identity systems](../identity-systems.md) page lists the ones tested with the server and what to check in yours.
- A database server, PostgreSQL, Microsoft SQL Server or MySQL, with an empty database on it.
- Somewhere to run the server, with a TLS certificate for the address that members and verifying Astrana instances will
  use, or a reverse proxy that terminates TLS in front of it.
- To build it, a Java 25 development kit, or Docker. The Maven wrapper in `java/` supplies Maven.

## Step 1. Register the server with your identity and access management system

Create a client for the server. Some systems call it an application or a relying party. Register the addresses below
exactly as written, with your own host name. Each address contains the name you give this registration in the server's
configuration. This page's examples use `keycloak`.

| Protocol       | Addresses to register                                                                                                                                                                                                                 |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OpenID Connect | Redirect address `https://your-server/login/oauth2/code/keycloak`. Post-logout redirect address `https://your-server/signed-out`.                                                                                                     |
| SAML           | Assertion consumer address `https://your-server/login/saml2/sso/keycloak`. Service provider metadata at `https://your-server/saml2/service-provider-metadata/keycloak`. Single logout address `https://your-server/logout/saml2/slo`. |

### OpenID Connect

Make the client confidential, using the authorisation code flow. Record the issuer address, the client identifier and
the client secret.

### SAML

The server is a service provider. It publishes its own metadata at the address above, which your identity system can
import. Record the identity provider's metadata address.

Give the server a signing certificate and private key of its own, as a PEM key file and a certificate file. Most
identity systems require signed authentication requests, and single logout always does. Without them the server can sign
members in only through an identity system that does not ask for signed requests, and single logout is not available. To
generate a throwaway pair for testing, run the command below with your own host name in place of the example. On
Windows, run it in Git Bash, which comes with Git for Windows and includes OpenSSL. `MSYS_NO_PATHCONV=1` at the start
stops Git Bash rewriting `/CN=...` as a Windows path, and does nothing elsewhere.

```bash
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:3072 -sha256 -nodes -days 1095 -subj "/CN=attest.example.org" \
  -keyout sp.key -out sp.crt
```

Record the service provider's entity identifier. It is the metadata address above unless you set another, and it must
match what the identity system has on record exactly.

Signing out can end the session at the identity system as well, over SAML single logout. That needs three things:

- The signing key and certificate above.
- A single logout endpoint advertised in the identity system's metadata.
- The server's own single logout address, set in Step 3 and registered with the identity system for its logout response.

Without all three, signing out ends the session at the server only.

### Which claim identifies the member

You grant a relationship to an identifier before the member has ever signed in. The server matches the member to it when
they sign in. The identifier therefore has to be one you already hold for each person, and one the identity system
presents every time.

By default the server uses the OpenID Connect `sub` claim or the SAML NameID. In most organisations the identifier your
system of record holds is something else, such as an employee number, a directory object identifier or a user principal
name, and the identity system exposes it on another claim. In that case you configure that claim in Step 3.

A persistent NameID that the identity system creates itself will not work. It is a pseudonym created at first sign-in,
so you cannot know it in advance.

### Who you are trusting

The server trusts the identity system completely. Whoever can change its claim mapping can sign in as any member and
register, clear, revoke or remove that member's relationships and keys. They cannot grant, extend or restore a
relationship. Put the client's registration and claim mapping under the same change control as the rest of the identity
system.

### Values you now have

- The issuer address, client identifier and client secret, for OpenID Connect.
- The identity provider's metadata address, your entity identifier, and the key and certificate files, for SAML.
- The name of the claim that identifies the member, if it is not the default.

## Step 2. Create the database

Create an empty database and a login for the application. On first run the server applies the schema for your engine
from `shared/schema`. It creates two tables, their indexes and five stored procedures, and nothing else. The login
therefore needs the right to create tables and procedures in that database. Keep the database for the server's own
tables only. The commands below create the database and the login, with `...` in place of a password you choose.

### PostgreSQL

```sql
CREATE ROLE ata_application LOGIN PASSWORD '...';
CREATE DATABASE trusted_attestation OWNER ata_application;
```

Set `trusted-attestation.database.provider` to `POSTGRESQL` and `spring.datasource.url` to
`jdbc:postgresql://db.example.org:5432/trusted_attestation?sslmode=verify-full`, with the login in
`spring.datasource.username` and `spring.datasource.password`. `sslmode=verify-full` encrypts the connection and checks
the certificate and the host name. It checks them against the certificate authority in `~/.postgresql/root.crt` for the
service's account, or in the file named by an `sslrootcert` parameter.

### SQL Server

```sql
CREATE DATABASE trusted_attestation;
GO
CREATE LOGIN ata_application WITH PASSWORD = '...';
USE trusted_attestation;
CREATE USER ata_application FOR LOGIN ata_application;
ALTER ROLE db_owner ADD MEMBER ata_application;
```

Set `trusted-attestation.database.provider` to `SQL_SERVER` and `spring.datasource.url` to
`jdbc:sqlserver://db.example.org:1433;databaseName=trusted_attestation;encrypt=true`. Give SQL Server a certificate the
Java trust store accepts. `trustServerCertificate=true` skips that check and belongs only in development.

### MySQL

```sql
CREATE DATABASE trusted_attestation CHARACTER SET utf8mb4;
CREATE USER 'ata_application'@'%' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON trusted_attestation.* TO 'ata_application'@'%';
```

Set `trusted-attestation.database.provider` to `MYSQL` and `spring.datasource.url` to
`jdbc:mysql://db.example.org:3306/trusted_attestation?sslMode=VERIFY_IDENTITY`. Give MySQL a certificate the Java trust
store accepts. `sslMode=VERIFY_IDENTITY` encrypts the connection and checks the certificate and the host name.

### A hardened deployment

With the login above, the application can call every procedure, so anyone who broke into the server could grant, extend
or restore relationships. A hardened deployment closes that gap. It keeps the application away from the procedures that
grant, revoke and extend relationships.

1. Apply the schema file yourself.
2. Set `trusted-attestation.database.create-schema-on-startup` to `false` in Step 3.
3. Create the two database roles the schema file describes in its comments.
4. Run the server as the application's role, and connect your own tooling as the organisation's role.

The application's role may read and delete relationship rows, update a relationship's key, insert into the audit log,
and execute two procedures, the member's own revoke and the procedure that deletes old audit entries. It must not be
able to call the grant, revoke and extend procedures, or write the columns only the organisation may change, which are
the type, the subtype, the revocation and the expiry. The organisation's role may execute those three procedures and
nothing else.

Each engine has its own points to get right. Most mistakes here produce no error, only a weaker boundary.

- On PostgreSQL, revoke `EXECUTE` on all five procedures from `PUBLIC` before granting, as the schema file shows.
  PostgreSQL grants it to every role by default, so until it is revoked any login can call the organisation's three
  procedures.
- On PostgreSQL, keep every procedure marked `SECURITY DEFINER` with its fixed search path, as the schema file has them.
  Without them the application's role would need direct table grants, and the boundary is gone.
- On PostgreSQL, the application's role also needs `USAGE` on the `audit_log_id_seq` sequence. Without it the first key
  registration fails, so unlike the other mistakes, this one shows at once.
- On SQL Server, the boundary relies on ownership chaining, so the procedures and the tables must have the same owner.
  That holds when one login applies the whole schema file into one schema. In any more complex setup, check it.
- On MySQL, the procedures run with the rights of the user who created them, so apply the schema as a separate
  administrative account that holds the table rights, and run the application as a new account that holds only what the
  schema file lists. To keep the Step 2 account for the application instead, make sure the administrative account
  created the procedures, because a procedure created by the Step 2 account would lose its table rights too. Then run
  `REVOKE ALL PRIVILEGES ON trusted_attestation.* FROM 'ata_application'@'%';` before the narrow grants, because they
  add to what an account already holds. Where you can, name the host the application connects from in place of `'%'`,
  which accepts the account from any host.

To check the grants, run `shared/test/role-checks.py` against a copy of the database. It creates two roles of its own,
gives them the grants the schema file documents, and reports what each can and cannot do. Pass the engine with
`--database`, as `postgres`, `mysql` or `mssql`. Pass `--sql-command` with a command that reads SQL from standard input
and runs it as an administrator. On MySQL, also pass `--as-application-command` and `--as-organisation-command`, each
with a command that runs SQL as one of the script's two roles, because MySQL cannot drop privileges within a session.
`python shared/test/role-checks.py --help` names the two roles and their password. The script changes the database it
runs against, so never point it at the live one. Once its report shows what you expect, give your own roles the same
grants.

## Step 3. Configure the server

Settings live in one YAML file, named `application.yml` or `application.yaml`, as Spring reads either. The container
reads it from `/app/config/application.yaml`. A jar reads it from the path given in
`--spring.config.additional-location`. The server's own settings sit under `trusted-attestation`. The identity system's
settings and the data source use Spring's own keys. The file holds the database password and the client secret, so make
it readable by the service's account alone.

Environment variables work too. Write the key in capitals with dots and hyphens turned into underscores, so
`trusted-attestation.tls.terminated-by-proxy` becomes `TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY` and
`spring.datasource.url` becomes `SPRING_DATASOURCE_URL`. The manifest fields given per locale, and the lists, are easier
to write correctly in the file.

The manifest settings are public and are served to anyone who asks. Everything else is private. The server builds the
manifest from a fixed list of the public fields, never from the configuration as a whole, so a private value cannot
reach it.

### A complete configuration

```yaml
spring:
  datasource:
    url: jdbc:postgresql://db.example.org:5432/trusted_attestation?sslmode=verify-full
    username: ata_application
    password: ...
  security:
    oauth2:
      client:
        provider:
          keycloak:
            issuer-uri: https://login.example.org/realms/staff
        registration:
          keycloak:
            client-id: trusted-attestation
            client-secret: ...
            authorization-grant-type: authorization_code
            scope: openid, profile, email

trusted-attestation:
  tls:
    terminated-by-proxy: true
  database:
    provider: POSTGRESQL
  manifest:
    default-locale: en
    name:
      en: Example Organisation
    description:
      en: Attestations for Example Organisation staff and clients
    website:
      en: https://www.example.org/
    privacy-notice-url:
      en: https://www.example.org/privacy
    relationship-types:
      - employee
      - client
    enrollment-url: https://ata.example.org/
    attestation-url: https://ata.example.org/api/v1/attest
```

### Database

| Setting                    | Key                                                     | Default and notes                                      |
| -------------------------- | ------------------------------------------------------- | ------------------------------------------------------ |
| Engine                     | `trusted-attestation.database.provider`                 | `POSTGRESQL`. The others are `MYSQL` and `SQL_SERVER`  |
| Connection                 | `spring.datasource.url`, `.username`, `.password`       | Required                                               |
| Create schema on first run | `trusted-attestation.database.create-schema-on-startup` | `true`. Set `false` when you apply the schema yourself |

### Identity system

The Spring keys below are shown for a registration named `keycloak`. Use the name you chose in Step 1.

| Setting                      | Key                                                                                                                             | Default and notes                                                                                                       |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Protocol                     | `trusted-attestation.iam.protocol`                                                                                              | `OIDC`. The other value is `SAML`                                                                                       |
| OpenID Connect issuer        | `spring.security.oauth2.client.provider.keycloak.issuer-uri`                                                                    | Required under OpenID Connect                                                                                           |
| Client identifier            | `spring.security.oauth2.client.registration.keycloak.client-id`                                                                 | Required under OpenID Connect                                                                                           |
| Client secret                | `spring.security.oauth2.client.registration.keycloak.client-secret`                                                             | Required under OpenID Connect                                                                                           |
| Scopes                       | `spring.security.oauth2.client.registration.keycloak.scope`                                                                     | None of its own. List `openid, profile, email` and any extra scope, because the list is used as written                 |
| Subject claim                | `trusted-attestation.iam.subject-claim`                                                                                         | `sub`. See Step 1                                                                                                       |
| Display name claim           | `trusted-attestation.iam.name-claim`                                                                                            | `name`. When your identity system sends no display name, the server shows the subject identifier                        |
| SAML entity identifier       | `spring.security.saml2.relyingparty.registration.keycloak.entity-id`                                                            | `{baseUrl}/saml2/service-provider-metadata/keycloak`, the metadata address                                              |
| SAML identity provider       | `spring.security.saml2.relyingparty.registration.keycloak.assertingparty.metadata-uri`                                          | Required under SAML                                                                                                     |
| SAML signing key             | `spring.security.saml2.relyingparty.registration.keycloak.signing.credentials[0].private-key-location`, `.certificate-location` | None. The PEM key and certificate files from Step 1, each written with the `file:` prefix, as in `file:/etc/ata/sp.key` |
| SAML single logout address   | `spring.security.saml2.relyingparty.registration.keycloak.singlelogout.url`                                                     | None. `{baseUrl}/logout/saml2/slo` to sign out at the identity system too                                               |
| SAML logout response address | `spring.security.saml2.relyingparty.registration.keycloak.singlelogout.response-url`                                            | The single logout address. Where the identity system sends its logout response                                          |
| SAML single logout binding   | `spring.security.saml2.relyingparty.registration.keycloak.singlelogout.binding`                                                 | `POST`. The other value is `REDIRECT`                                                                                   |

Use `https://` addresses for the identity system. The server does not refuse a plain HTTP address, so check it yourself.
If your identity system uses a certificate from a private authority, trust that authority in the Java trust store. The
server has no setting to skip certificate validation.

### TLS

The server refuses to run without TLS. Either it holds the certificate itself, or a proxy in front of it does and you
set `trusted-attestation.tls.terminated-by-proxy` to `true`.

| Setting                          | Key                                           | Default and notes                                      |
| -------------------------------- | --------------------------------------------- | ------------------------------------------------------ |
| Terminated by a proxy            | `trusted-attestation.tls.terminated-by-proxy` | `false`. Set `true` when a proxy holds the certificate |
| Its own certificate              | `server.ssl.*`, `server.port`                 | Spring's own TLS settings                              |
| Strict-Transport-Security header | `trusted-attestation.tls.hsts-enabled`        | `true`. One year, no subdomains                        |

Behind a proxy, the server trusts the forwarded scheme and host headers the proxy sets, and refuses a request unless its
`X-Forwarded-Proto` header holds exactly one value, `https`. It still serves a request without the header. Put the
server on a network only the proxy can reach. The proxy sets the minimum TLS version. When the server holds the
certificate itself, it serves TLS 1.2 or later.

The Strict-Transport-Security header tells a browser to use only HTTPS for your address for a year. The server sends it
on `localhost` too. A browser that accepts it there then uses only HTTPS for every port on `localhost`, so the
development profile turns it off. To cover subdomains or ask for preload, set the header at your proxy instead and turn
the server's off, so that the browser receives one header rather than two.

### Audit retention

The audit log keeps an entry for every change to a relationship. The server itself removes entries older than the
retention period. Nothing else has to be scheduled.

| Setting      | Key                                        | Default and notes                                                                                   |
| ------------ | ------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| Retention    | `trusted-attestation.audit.retention-days` | `1825`, which is five years. From 1 to 36,500 days                                                  |
| When it runs | `trusted-attestation.audit.cron`           | `0 0 2 * * *`. A Spring cron expression of six fields, seconds first, in server time                |
| On or off    | `trusted-attestation.audit.prune-enabled`  | `true`. Set `false` if your own job prunes, connected as a login that may execute `prune_audit_log` |

### The manifest

The manifest is public. A verifying Astrana instance reads it to find your attestation endpoint and to show its owner
who you are. Its keys sit under `trusted-attestation.manifest`. Give each text field once for each locale. Where a field
has no entry for a locale, the default locale's entry is used.

| Field                | Key                           | Required and notes                                                                                   |
| -------------------- | ----------------------------- | ---------------------------------------------------------------------------------------------------- |
| Organisation name    | `name`                        | Required, per locale                                                                                 |
| Description          | `description`                 | Optional, per locale                                                                                 |
| Website              | `website`                     | Optional, per locale                                                                                 |
| Privacy notice       | `privacy-notice-url`          | Optional, per locale                                                                                 |
| Support page         | `support-url`                 | Optional, per locale. The page a member who holds no relationship yet is sent to                     |
| Logo                 | `logo-data`, `logo-data-dark` | Optional. A file path or a `data:` URI, see below. The dark logo needs the light one                 |
| Jurisdictions        | `jurisdictions`               | Optional. ISO 3166-1 country or ISO 3166-2 subdivision codes for where you operate or are registered |
| Relationship types   | `relationship-types`          | Required, at least one, from `shared/contract/relationship-types.json`                               |
| Landing page address | `enrollment-url`              | Required. Where members sign in, normally the server's own root address                              |
| Attestation endpoint | `attestation-url`             | Required. The server's `/api/v1/attest`                                                              |
| Default locale       | `default-locale`              | `en`                                                                                                 |
| Offered locales      | `supported-locales`           | Empty, which offers every locale that ships. Private, see Localisation                               |

The logo is embedded in the manifest as a `data:` URI. Give either the URI itself, or the path to a PNG, JPEG, GIF, WebP
or SVG file, which the server reads and encodes when it starts. A relative path is resolved against the working
directory, which is `/app` in the container. Keep the data URI to 65,536 characters or fewer, about 48 kilobytes of
image, because every verifying Astrana instance downloads it. The manifest schema sets that limit.

The manifest's version, `trusted-attestation.manifest.manifest-version`, is always `1` and needs no setting. Any other
value stops the server.

The server validates the whole manifest when it starts. It refuses to run in any of these cases.

- A required field is missing.
- An address is not an absolute `https://` address.
- A locale is not written as a language tag, such as `en` or `fr-CA`.
- A field given per locale has no entry for the default locale.
- An entry in a field given per locale is empty.
- A relationship type is not in the vocabulary, or is listed twice.
- A jurisdiction is not written as an ISO 3166 code, or is listed twice.
- A logo is not a base64 image data URI, or is over 65,536 characters.
- A dark logo is given without a light one.
- A logo file cannot be read.

### Localisation

The pages ship in 58 locales, from `shared/ui/ui-strings.json`. Where a locale has no translation for a string, the page
shows the English one. The translations were drafted by machine, so have a native speaker read the ones your members
will see before you go live. Arabic, Hebrew, Persian, Urdu and Pashto are laid out right to left.

The server picks a request's locale from these sources, in this order:

1. The language the member picked with the switcher at the top of the page.
2. A `locale` claim from your identity system.
3. The browser's Accept-Language list, in full. A region such as `fr-CA` falls back to its language.
4. Your default locale.

`trusted-attestation.manifest.supported-locales` is the ordered list of locales the pages offer. Leave it empty to offer
every locale that ships, in alphabetical order of each language's own name with your default locale first. The list
limits every source above, not only the switcher. The server skips a claim or browser preference that is not on the list
and tries the next source. A listed locale that does not ship is ignored. The switcher appears only when more than one
locale remains. The setting is not published in the manifest.

<p>
  <img src="../screenshots/demo-me-page-localised-glyphs.png" alt="The self-service page in Simplified Chinese. The switcher at the top right shows the language's own name, and the labels, statuses and buttons are translated, in light mode." width="49%">
  <img src="../screenshots/demo-me-page-localised-glyphs-darkmode.png" alt="The self-service page in Simplified Chinese. The switcher at the top right shows the language's own name, and the labels, statuses and buttons are translated, in dark mode." width="49%">
</p>

<p>
  <img src="../screenshots/demo-me-page-localised-rtl.png" alt="The self-service page in Arabic, laid out right to left. The switcher sits at the top left, the organisation's name and the relationship card are aligned to the right, and the key is shown in a left-to-right field, in light mode." width="49%">
  <img src="../screenshots/demo-me-page-localised-rtl-darkmode.png" alt="The self-service page in Arabic, laid out right to left. The switcher sits at the top left, the organisation's name and the relationship card are aligned to the right, and the key is shown in a left-to-right field, in dark mode." width="49%">
</p>

The server never translates your own text. Give the organisation's name, description, website, privacy notice and
support page in every locale you offer. If you leave one out, members see that text in the default locale on a page that
is otherwise translated.

### Branding

The pages come in light and dark, and follow the member's own device setting, with no switch on the page. Give a dark
logo as well as the light one in the manifest, or the light logo is shown in both. A colour that should differ between
the two goes in the matching block of `theme-overrides.css`, as its examples show.

Your name and logo come from the manifest. Colours and fonts come from `theme-overrides.css`, a short file of CSS custom
properties served at `/theme-overrides.css`. It ships as commented examples, each saying what it controls. Keep the
contrast at 4.5:1 for text and 3:1 for large text and controls. Those are the levels the Web Content Accessibility
Guidelines (WCAG) 2.1 AA set, and the pages are built to meet them.

The file is packaged into the jar from `shared/ui` at build time, so it cannot be edited in place on a running server.
Either edit it in `shared/ui` and rebuild, or have your proxy serve your own copy at that address. The favicon,
`favicon.svg`, is packaged the same way.

## Step 4. Run it

The server will not start in any of these cases, and its log says which one.

- TLS is not configured.
- It cannot reach the database.
- The schema is missing while creation at start-up is off.
- It cannot fetch the OpenID Connect discovery document or, under SAML, the identity provider's metadata.
- The manifest fails one of the checks listed under The manifest in Step 3.
- The SAML signing key or certificate cannot be read.

A wrong client secret only shows when the first member signs in.

### As a container

Pull the published image, naming the full version of the release you want. Each release has three tags. One is the full
version, such as `1.0.0`. One is the major and minor version, such as `1.0`. The third is `latest`. The image is built
for 64-bit Intel and AMD processors (amd64) only.

```bash
docker pull astrana/ata-java:1.0.0
```

On any other processor, or to build it yourself, build the image from the repository root instead.

```bash
docker build -f java/Dockerfile -t astrana/ata-java:1.0.0 .
```

The licences and copyright notices for the third-party software in the image are in `/app/THIRD-PARTY-NOTICES.txt`. The
server also serves that file at `/THIRD-PARTY-NOTICES.txt`, and the licence page links to it.

The container holds no certificate, so the configuration file includes `terminated-by-proxy: true`. Run the container on
a port only your proxy can reach, with the configuration file mounted.

```bash
docker run -d --name ata --restart unless-stopped -v /etc/ata/application.yaml:/app/config/application.yaml:ro \
  -p 127.0.0.1:8080:8080 astrana/ata-java:1.0.0
```

Put a TLS-terminating proxy in front. The proxy must set `X-Forwarded-Proto` and `X-Forwarded-Host` itself, from the
request it received, and replace any the client sent. The server trusts these headers when
`trusted-attestation.tls.terminated-by-proxy` is `true`. A client that could set them could choose the scheme and host
the server uses to build its addresses. The server also trusts `X-Forwarded-Port` and `X-Forwarded-Prefix`, so the proxy
removes those. It takes the scheme from `X-Forwarded-Proto` alone and ignores `X-Forwarded-Ssl` and the `Forwarded`
header of Request for Comments (RFC) 7239. The configurations below remove those two as well, so nothing further along
can be misled by them.

With Caddy, this is the whole configuration. Caddy obtains and renews the certificate itself, and it sets
`X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-For` from the request it received and overwrites what the
client sent. It leaves the other four headers alone, so the four `header_up` lines remove them.

```
ata.example.org {
    reverse_proxy 127.0.0.1:8080 {
        header_up -Forwarded
        header_up -X-Forwarded-Port
        header_up -X-Forwarded-Prefix
        header_up -X-Forwarded-Ssl
    }
}
```

With Nginx, terminate TLS in the server block and forward with these lines. `proxy_set_header` replaces a header the
client sent with the proxy's own value, and an empty value removes the header. Without the `proxy_set_header Host` line,
Nginx sends the container's own address as `Host`.

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header Forwarded "";
    proxy_set_header X-Forwarded-Port "";
    proxy_set_header X-Forwarded-Prefix "";
    proxy_set_header X-Forwarded-Ssl "";
}
```

With another proxy, do the same with its own directives. The container must reach your database and your identity system
by the addresses in its configuration. The identity system must be able to redirect members back to the address your
proxy serves. The demonstration in `java/demo` is a complete working example of a container behind a proxy, with a
database and an identity system you would replace with your own.

### Installed on a server

Building needs a Java 25 development kit, and the Maven wrapper in `java/` supplies Maven. Running needs a Java 25
runtime.

```bash
cd java && ./mvnw -DskipTests package
```

The result is `java/target/trusted-attestation-server-<version>.jar`, with the version of the release, such as
`trusted-attestation-server-1.0.0.jar`. It carries no development configuration. Run it as a service.

```bash
java -jar trusted-attestation-server-1.0.0.jar --spring.config.additional-location=/etc/ata/application.yml
```

Either run it behind a proxy that holds the certificate and sets the forwarded headers as shown for the container, and
set `terminated-by-proxy: true`. Or point `server.ssl.*` at its own certificate. Behind a proxy, set
`server.address: 127.0.0.1` so that only the proxy on the same host can reach the server, or put the server on a network
only the proxy can reach.

### Confirm it works

- `https://your-server/.well-known/ata-manifest.json` returns your manifest, with nothing private in it.
- `https://your-server/` offers sign-in through your identity system. A member who has been granted nothing sees a page
  saying that nothing has been added for them yet. The page links to your support page, or to your website if you set no
  support page.
- The attestation endpoint answers `{"valid":false}` and nothing else for a key it does not hold. Any 32 bytes in base64
  will do.

```bash
curl -s https://your-server/api/v1/attest -H "Content-Type: application/json" -d '{"public_key":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAE="}'
```

## Step 5. Grant, revoke and extend relationships from your systems

A member has no relationships until you grant them. The server has no page and no endpoint for granting, revoking or
extending. Your own systems do all three by calling procedures in the database, connected as the organisation's role. In
practice that is a feed from the system of record, such as the human resources system for staff or the customer or case
system for clients. Run it as a scheduled job under that system's change control, so that a relationship ends in the
server when it ends in the record.

| Procedure                    | Arguments                                                        | Effect                                                                                                                                                                   |
| ---------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `grant_member_relationship`  | subject identifier, relationship type, subtype or null, actor    | Creates the relationship, with no key yet. Fails if it already exists, if the type is not in the vocabulary, or if the subject identifier has leading or trailing spaces |
| `revoke_member_relationship` | subject identifier, relationship type, actor                     | Marks it revoked. The row stays, so it can be restored. Fails if there is no such relationship                                                                           |
| `extend_member_relationship` | subject identifier, relationship type, new expiry or null, actor | Clears a revocation, sets or clears the expiry, restores an expired relationship. Fails if there is none                                                                 |

The subject identifier is the value of the subject claim you configured. You need it before the member signs in, because
you grant the relationship to it. The actor is free text naming who or what made the change, and goes into the audit
log.

Four rules apply to the calls.

- On PostgreSQL and MySQL, call the procedures with `CALL`. On SQL Server, use `EXEC`.
- MySQL requires every argument, null included.
- Call each procedure on its own, outside any transaction your tooling opens. On MySQL a procedure starts its own
  transaction, which commits any transaction already open.
- An expiry is a moment in Coordinated Universal Time (UTC). A date on its own means midnight at the start of that day.
  On PostgreSQL the expiry carries a time zone, and a value written without an offset is read in the session's time
  zone, so give the offset, as in `'2027-04-01 00:00:00+00'`.

When the member signs in, the relationship is there, waiting for their key.

<p>
  <img src="../screenshots/demo-me-page-key-unset-relationship.png" alt="The self-service page after a grant. One relationship card, marked Waiting For Your Key, with an empty field for the attestation key and a Save Key button, in light mode." width="49%">
  <img src="../screenshots/demo-me-page-key-unset-relationship-darkmode.png" alt="The self-service page after a grant. One relationship card, marked Waiting For Your Key, with an empty field for the attestation key and a Save Key button, in dark mode." width="49%">
</p>

A member whose relationship is revoked can still sign in and register a key. The relationship stays revoked until you
extend it. A member can revoke a relationship themselves, which only you can undo. A member can also remove a
relationship entirely, after which only a new grant brings it back.

## Keeping it running

- Back up the database as you would any other. Everything the server holds is in its two tables.
- Point your load balancer's or monitoring system's health probe at `/.well-known/ata-manifest.json`. It needs no
  sign-in and is cheap to serve. A server that answers it passed every start-up check. The probe does not touch the
  database. If you need a dependency check, probe the attestation endpoint with a key you registered for that purpose.
- Set the audit retention to match your records policy. Every grant, revoke, extension, key registration, key clearing,
  removal and member's own revoke is in the log, with the member's subject identifier. For the organisation's own
  changes, the log also holds the actor your tooling passed. Keys and attestation lookups are never written to it, so
  your auditors can read it without seeing anything a member or a verifying Astrana instance has not already seen.
- New versions come as new releases of this implementation. [Versions and releases](../versions.md) says what each
  number means and what to read before you update, including any schema change you apply by hand.
- Report a security problem privately, as [SECURITY.md](../../SECURITY.md) describes.
