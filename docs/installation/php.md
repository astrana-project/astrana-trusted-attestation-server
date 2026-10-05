# Installing the PHP implementation

The PHP implementation is for any organisation that prefers PHP, or that has a web hosting account and nothing more. It
runs on Laravel, on PHP 8.4.1 or later.

You can install it on ordinary Linux or Windows shared hosting, or on a server of your own under Apache, Nginx or
Internet Information Services (IIS). You can also run it as the Docker container image `astrana/ata-php`, which listens
on port 8000 inside the container. It is well suited to and aligned with small organisations, such as clubs, community
groups, congregations, charities and small businesses. They usually have a hosting account, a Google Workspace or
Microsoft 365 subscription and no systems team, and the steps below are written for that setting.

**Installation has five steps:**

1. Register the server with the service your members already sign in to.
2. Create an empty database.
3. Write the settings file.
4. Put the server online and confirm it works.
5. Grant, revoke and extend relationships.

The server creates its own tables on its first request. There is no administrator interface. After installation, you
grant, revoke and extend relationships from your hosting account's database tool.

## Where to run it

You can run the server in one of three ways. Only Step 4, putting it online, differs between them. Choose the one that
fits what you already have.

- **On shared hosting.** You prepare the files on your own computer and upload them to your hosting account, Linux or
  Windows. This suits most small organisations, because the host runs the web server, the database and the certificate
  for you.
- **On a server of your own.** You install it under Apache, Nginx or IIS on a server you manage. This suits an
  organisation that already runs its own web server and wants to keep the server alongside its other sites.
- **As a Docker container.** You pull the published image and run it behind a reverse proxy that holds the certificate.
  This suits an organisation that already runs containers. The image serves one request at a time, so if you expect more
  traffic than a small organisation has, choose one of the other two. The published image supports MySQL and PostgreSQL.
  For SQL Server you build an image yourself.

## Before you start

You need four things:

- A sign-in service that supports OpenID Connect or SAML 2.0 and already has an account for every person you intend to
  grant a relationship to. Google Workspace and Microsoft 365 both do, and so do Keycloak, Authentik and the other
  identity and access management systems. This page calls it the sign-in service.
- A hosting account, or a server, with PHP 8.4.1 or later and a MySQL, Microsoft SQL Server or PostgreSQL database. PHP
  needs these extensions, which hosting accounts normally provide: `mbstring`, `openssl`, `dom`, `fileinfo`,
  `tokenizer`, `curl` and `intl`. Composer stops the installation if one is missing. PHP also needs the PHP Data Objects
  (PDO) driver for your database, `pdo_mysql`, `pdo_sqlsrv` or `pdo_pgsql`.
- A domain or subdomain for the server, such as `attest.example.org`, with a TLS certificate. Hosting control panels
  issue one from Let's Encrypt in a few clicks.
- On your own computer, PHP 8.4.1 or later and Composer, PHP's package manager, from
  [getcomposer.org](https://getcomposer.org), to prepare the files you upload. Or Docker, if you run it as a container.

## Step 1. Register the server with your sign-in service

Create a client for the server. Google calls it an OAuth client, Microsoft an app registration, and other systems an
application or a relying party. It is a web application with a client secret, which the server uses to collect the
member's details from the sign-in service after they sign in. Register the addresses below exactly as written, with your
own host name.

| Protocol       | Addresses to register                                                                                                                                                                                                                                 |
| -------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| OpenID Connect | Redirect address `https://your-server/auth/callback`. Sign-out return address `https://your-server/signed-out`.                                                                                                                                       |
| SAML           | Assertion consumer address `https://your-server/auth/saml/acs`. Single logout address `https://your-server/auth/saml/logout`, which accepts the Redirect and the POST binding. Service provider metadata at `https://your-server/auth/saml/metadata`. |

Microsoft takes the sign-out return address as a second redirect address. Google has no sign-out address to register.

### Google Workspace

1. In the Google Cloud console, create a project.
2. Set its OAuth consent screen's user type to Internal. That limits sign-in to accounts in your Workspace. The server
   does not check which domain a Google account belongs to, so with any other user type every Google account can sign
   in.
3. Create an OAuth client of type Web application, with the redirect address above.
4. Record the client identifier and the client secret. The issuer address is `https://accounts.google.com`.

### Microsoft 365

1. In the Microsoft Entra admin centre, register a single-tenant application with a Web redirect address.
2. Under Certificates and secrets, create a client secret.
3. Under Token configuration, add the optional claim `email` to the ID token. Make sure every member's account has an
   email address, because Microsoft leaves the claim out otherwise.
4. Record the client identifier and the client secret. The issuer address is
   `https://login.microsoftonline.com/<directory (tenant) ID>/v2.0`, using the directory (tenant) ID shown on the
   registration's overview page, not your domain name. The server compares the issuer exactly.

### Keycloak, Authentik and other systems

Create a confidential client using the authorisation code flow. Record the issuer address, which the system's
administration page shows, the client identifier and the client secret.

### SAML

Under SAML, the server is a service provider, meaning the site a person signs in to. The sign-in service is the identity
provider. The server publishes its own metadata, a description of itself, at the address above, which the sign-in
service can import. Record the identity provider's metadata address.

Give the server a signing certificate and private key of its own. Most sign-in services require signed authentication
requests, and single logout always does. Without them the server sends its requests unsigned, and a sign-in service that
requires signed requests refuses them. Generate them in the application folder with OpenSSL, with your own host name in
place of the example. On Windows, run it in Git Bash, which comes with Git for Windows and includes OpenSSL.
`MSYS_NO_PATHCONV=1` at the start stops Git Bash rewriting `/CN=...` as a Windows path, and does nothing elsewhere.

```bash
mkdir -p saml
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:3072 -sha256 -nodes -days 1095 -subj "/CN=attest.example.org" \
  -keyout saml/sp-private.key -out saml/sp-certificate.crt
```

The server's entity identifier is the name the sign-in service knows it by. It is the metadata address unless you set
another, and it must match what the sign-in service has on record exactly.

### Which claim identifies the member

A claim is a named piece of information the sign-in service sends about the person who signed in, such as their email
address. You grant a relationship to an identifier before the member has ever signed in. The server matches the member
to it when they sign in. The identifier therefore has to be one you already hold for each person, and one the sign-in
service presents every time.

Google and Microsoft identify an account by an opaque code, the `sub` claim, which you do not know in advance. The
practical choice for a small organisation is the member's email address at your domain, which you do know. Set the
subject claim to `email` in Step 3, and grant relationships to addresses. If a member's address changes, grant the
relationship to the new address and revoke the old one.

Use the email address only where members cannot change it themselves. The server does not check whether the sign-in
service has verified the address. In Microsoft 365, anyone who can edit a user's email address can sign in as that
member.

Under SAML, a persistent NameID that the sign-in service creates itself will not work. It is a pseudonym created at
first sign-in, so you cannot know it in advance.

### Who you are trusting

The server trusts the sign-in service completely. Whoever administers it can sign in as any member and register, clear,
revoke or remove that member's relationships and keys. They cannot grant, extend or restore a relationship. Keep its
administration to the people who already hold that trust.

### Values you now have

- The issuer address, client identifier and client secret, for OpenID Connect.
- The identity provider's metadata address and, if you made them, the two files in `saml/`, for SAML.
- The name of the claim that identifies the member, `email` for Google or Microsoft.

## Step 2. Create the database

In your hosting control panel, create an empty database and a database user with full rights on it. Record the host, the
database name, the user and the password. On its first request the server applies the schema for your engine from
`shared/schema`. It creates two tables, their indexes and five stored procedures, and nothing else. Keep that database
for the server's own tables only.

On a database server of your own, the commands below create the database and a login that may create tables and
procedures in it, with `...` in place of a password you choose.

### MySQL

Most Linux hosting offers MySQL. Set `DB_CONNECTION` to `mysql` in Step 3, with the details you recorded. To encrypt the
connection to a database on another machine and check its certificate, also set `MYSQL_ATTR_SSL_CA` to the path of the
certificate authority's file.

```sql
CREATE DATABASE trusted_attestation CHARACTER SET utf8mb4;
CREATE USER 'ata_application'@'%' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON trusted_attestation.* TO 'ata_application'@'%';
```

### SQL Server

Windows hosting, such as Plesk on Windows, commonly offers Microsoft SQL Server. Set `DB_CONNECTION` to `sqlsrv` in
Step 3. PHP needs the `pdo_sqlsrv` extension and Microsoft's Open Database Connectivity (ODBC) driver, ODBC Driver 18
for SQL Server, on Windows and Linux alike. Windows hosting that offers SQL Server usually provides both. The published
container image holds neither, so it supports only MySQL and PostgreSQL. To use SQL Server from a container, build an
image with SQL Server support yourself, as [As a container](#as-a-container) in Step 4 describes. The connection is
encrypted and checks the server's certificate. `DB_TRUST_SERVER_CERTIFICATE=true` skips that check and belongs only in
development.

```sql
CREATE DATABASE trusted_attestation;
GO
CREATE LOGIN ata_application WITH PASSWORD = '...';
USE trusted_attestation;
CREATE USER ata_application FOR LOGIN ata_application;
ALTER ROLE db_owner ADD MEMBER ata_application;
```

### PostgreSQL

Some hosting offers PostgreSQL. PHP needs the `pdo_pgsql` extension. Set `DB_CONNECTION` to `pgsql` in Step 3. To
encrypt the connection to a database on another machine and check its certificate and host name, also set `DB_SSLMODE`
to `verify-full`. Then put the certificate authority's file where PostgreSQL's client library looks for it. That is the
path in the `PGSSLROOTCERT` environment variable, or `~/.postgresql/root.crt` for the account PHP runs as.

```sql
CREATE ROLE ata_application LOGIN PASSWORD '...';
CREATE DATABASE trusted_attestation OWNER ata_application;
```

### Who may grant, revoke and extend

On a hosting account, the server uses the same database user that you use to grant, revoke and extend. The server has no
page and no endpoint that grants, extends or restores a relationship. But if someone broke into the server, nothing in
the database would stop them calling those procedures as that user. On a database server of your own, two separate
roles, one for the application and one for the organisation's changes, make the database enforce the rule that only the
organisation grants, extends or restores a relationship. To use them, apply the schema yourself, set
`TRUSTED_ATTESTATION_CREATE_SCHEMA=false` in Step 3, and create the roles as
[the .NET page's hardened deployment](dotnet.md#a-hardened-deployment) describes. They are the same on every
implementation.

## Step 3. Write the settings file

Settings live in a file named `.env` in the application folder, one `KEY=value` per line. Text that can be given in more
than one language is a JSON object keyed by language. Lists are comma-separated. The file holds the database password,
the client secret and the application key, so make it readable by the web server's account alone.

Put single quotes around a JSON value in `.env`, as in `'{"en":"Example Rowing Club"}'`, because the file's parser
refuses an unquoted value that contains a space. In a Docker environment file, leave the quotes off, because Docker
passes them through to the server.

The manifest settings are public and are served to anyone who asks. Everything else is private. The server builds the
manifest from a fixed list of the public fields, never from the file as a whole, so a private value cannot reach it.

### A complete settings file

A club on shared hosting, whose members sign in with Google Workspace accounts at `example.org`:

```
APP_URL=https://attest.example.org
APP_KEY=base64:...
SESSION_DRIVER=file
CACHE_STORE=file

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=exampleorg_attest
DB_USERNAME=exampleorg_attest
DB_PASSWORD=...

TRUSTED_ATTESTATION_IAM_PROTOCOL=oidc
TRUSTED_ATTESTATION_IAM_ISSUER=https://accounts.google.com
TRUSTED_ATTESTATION_IAM_CLIENT_ID=....apps.googleusercontent.com
TRUSTED_ATTESTATION_IAM_CLIENT_SECRET=...
TRUSTED_ATTESTATION_IAM_SUBJECT_CLAIM=email

TRUSTED_ATTESTATION_MANIFEST_DEFAULT_LOCALE=en
TRUSTED_ATTESTATION_MANIFEST_NAME='{"en":"Example Rowing Club"}'
TRUSTED_ATTESTATION_MANIFEST_DESCRIPTION='{"en":"Attestations for members and coaches of Example Rowing Club"}'
TRUSTED_ATTESTATION_MANIFEST_WEBSITE='{"en":"https://www.example.org/"}'
TRUSTED_ATTESTATION_MANIFEST_PRIVACY_NOTICE_URL='{"en":"https://www.example.org/privacy"}'
TRUSTED_ATTESTATION_MANIFEST_RELATIONSHIP_TYPES=member,volunteer
TRUSTED_ATTESTATION_MANIFEST_ENROLLMENT_URL=https://attest.example.org/
TRUSTED_ATTESTATION_MANIFEST_ATTESTATION_URL=https://attest.example.org/api/v1/attest
```

### Application

| Setting         | Key              | Default and notes                                                      |
| --------------- | ---------------- | ---------------------------------------------------------------------- |
| Public address  | `APP_URL`        | Required. An `https://` address                                        |
| Application key | `APP_KEY`        | Required. Generated in Step 4 with `php artisan key:generate`          |
| Cache           | `CACHE_STORE`    | `file`, because the database holds nothing but the server's own tables |
| Sessions        | `SESSION_DRIVER` | `file`, for the same reason                                            |

Leave every other `SESSION_` key unset. Their defaults name the session cookie `ata_session`, send it only over HTTPS,
and end an idle session after eight hours, as the other implementations do.

### Database

| Setting                      | Key                                                               | Default and notes                                                                                             |
| ---------------------------- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Engine                       | `DB_CONNECTION`                                                   | Required. One of `mysql`, `pgsql`, `sqlsrv`. Also picks the schema file                                       |
| Connection                   | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Required                                                                                                      |
| Create schema on first run   | `TRUSTED_ATTESTATION_CREATE_SCHEMA`                               | `true`. Set `false` when you apply the schema yourself                                                        |
| PostgreSQL encryption        | `DB_SSLMODE`                                                      | `prefer`, which encrypts when the server offers it and checks nothing. Set `verify-full`, as Step 2 describes |
| MySQL certificate authority  | `MYSQL_ATTR_SSL_CA`                                               | None. The path to the authority's file, which encrypts the connection and checks the server's certificate     |
| SQL Server certificate check | `DB_TRUST_SERVER_CERTIFICATE`                                     | `false`. `true` skips the check and belongs only in development                                               |

### Sign-in service

| Setting                         | Key                                                                                  | Default and notes                                                                                                                                             |
| ------------------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Protocol                        | `TRUSTED_ATTESTATION_IAM_PROTOCOL`                                                   | `oidc`. The other value is `saml`                                                                                                                             |
| OpenID Connect issuer           | `TRUSTED_ATTESTATION_IAM_ISSUER`                                                     | Required under OpenID Connect                                                                                                                                 |
| Discovery address               | `TRUSTED_ATTESTATION_IAM_DISCOVERY_URL`                                              | None. Only when the issuer is reached by a different address from inside your network. Use an `https://` address. The server does not refuse a plain HTTP one |
| Client identifier               | `TRUSTED_ATTESTATION_IAM_CLIENT_ID`                                                  | Required under OpenID Connect                                                                                                                                 |
| Client secret                   | `TRUSTED_ATTESTATION_IAM_CLIENT_SECRET`                                              | Required under OpenID Connect                                                                                                                                 |
| Subject claim                   | `TRUSTED_ATTESTATION_IAM_SUBJECT_CLAIM`                                              | `sub`. Set `email` for Google or Microsoft                                                                                                                    |
| Display name claim              | `TRUSTED_ATTESTATION_IAM_NAME_CLAIM`                                                 | `name`. When your identity system sends no display name, the server shows the subject identifier                                                              |
| Extra scopes                    | `TRUSTED_ATTESTATION_IAM_SCOPES`                                                     | None. Comma-separated, added to `openid`, `profile` and `email`                                                                                               |
| HTTPS issuer                    | `TRUSTED_ATTESTATION_IAM_REQUIRE_HTTPS_METADATA`                                     | `true`. Refuses an issuer address that is not `https://`                                                                                                      |
| SAML entity identifier          | `TRUSTED_ATTESTATION_SAML_ENTITY_ID`                                                 | The metadata address                                                                                                                                          |
| SAML identity provider metadata | `TRUSTED_ATTESTATION_SAML_IDP_METADATA_URL`                                          | Required under SAML                                                                                                                                           |
| SAML signing key                | `TRUSTED_ATTESTATION_SAML_SP_PRIVATE_KEY`, `TRUSTED_ATTESTATION_SAML_SP_CERTIFICATE` | None. Paths relative to the application folder unless they are absolute                                                                                       |

Under SAML, the subject claim names an attribute, and the assertion's NameID stands in when that attribute is absent,
blank or carries more than one value.

Use `https://` addresses for the sign-in service. `TRUSTED_ATTESTATION_IAM_REQUIRE_HTTPS_METADATA` exists for
development against a local identity provider and stays `true` in a deployment. Under SAML the server does not refuse a
plain HTTP metadata address, so check it yourself. If you run your own sign-in service with a certificate from a private
authority, trust that authority through `curl.cainfo` in `php.ini`. The server has no setting to skip certificate
validation.

### TLS

The server refuses to run without TLS, and it never holds the certificate itself. On a hosting account the web server
holds it, and `APP_URL` must start with `https://`. The server then answers any request that did not arrive over TLS
with HTTP 421 - Misdirected Request and no body. Behind a proxy of your own, or in the container, set
`TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY=true`.

| Setting                  | Key                                           | Default and notes                                                             |
| ------------------------ | --------------------------------------------- | ----------------------------------------------------------------------------- |
| Terminated by a proxy    | `TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY` | `false`. Set `true` when a proxy holds the certificate                        |
| Proxy addresses to trust | `TRUSTED_ATTESTATION_TRUSTED_PROXIES`         | Every address. Set it to your proxy's address, comma-separated ranges allowed |

Behind a proxy, the server trusts the forwarded headers from the addresses in `TRUSTED_ATTESTATION_TRUSTED_PROXIES`, and
refuses a request unless its `X-Forwarded-Proto` header holds exactly one value, `https`. It still serves a request
without the header. Set that list to your proxy's address, and put the server on a network only the proxy can reach. The
default, every address, is for a container behind a proxy on a private network, where nothing but the proxy can reach
the server. Anywhere else, a client that reaches the server directly could set the forwarded headers itself.

The server does not send HTTP Strict Transport Security (HSTS), the header that tells a browser to use only HTTPS for
your address, because the web server or proxy that holds the certificate owns that policy. Set it there, for one year.
Adding `includeSubDomains` makes browsers use HTTPS for every subdomain of the address too, so add it only if every one
of them is served over HTTPS.

| Where it is set   | How                                                                                                  |
| ----------------- | ---------------------------------------------------------------------------------------------------- |
| Apache            | `Header always set Strict-Transport-Security "max-age=31536000"` in the virtual host                 |
| Nginx             | `add_header Strict-Transport-Security "max-age=31536000" always;` in the server                      |
| Caddy             | `header Strict-Transport-Security "max-age=31536000"` in the site                                    |
| IIS               | The site's HSTS settings in IIS Manager, enabled with a maximum age of `31536000`                    |
| A hosting account | The control panel's TLS or security settings, which usually offer HSTS as a switch, or ask your host |

The stylesheet, the favicon and the other files in `public/` are served by the web server itself, so they do not carry
the security headers the server sets on its own responses. Under Apache, `public/.htaccess` adds them, and under IIS,
`public/web.config` does. Under Nginx, or on a hosting panel that reads neither file, add them yourself in the location
that serves those files. Inside a location that sets its own headers, Nginx drops the headers set at server level, so
repeat the HSTS line there.

```nginx
location ~* \.(css|svg|ico|png|js|txt|woff2)$ {
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header X-XSS-Protection "0" always;
    add_header Cache-Control "no-cache, no-store, max-age=0, must-revalidate" always;
    add_header Strict-Transport-Security "max-age=31536000" always;
    try_files $uri =404;
}
```

In the container, the image's router script, `server.php`, sets these headers on the files in `public/` itself.

### Audit retention

The audit log keeps an entry for every change to a relationship. The server itself removes entries older than the
retention period, while serving ordinary requests. There is no scheduled task to set up.

| Setting             | Key                                           | Default and notes                                                                                   |
| ------------------- | --------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Retention           | `TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS`    | `1825`, which is five years. From 1 to 36,500 days                                                  |
| When it runs        | `TRUSTED_ATTESTATION_AUDIT_INTERVAL_HOURS`    | `24`. On an incoming request, at most once in that interval. From 1 to 8,760 hours                  |
| How often it checks | `TRUSTED_ATTESTATION_AUDIT_CHECK_PROBABILITY` | `200`. One request in that many checks whether it is due. 1 or more                                 |
| On or off           | `TRUSTED_ATTESTATION_AUDIT_PRUNE_ENABLED`     | `true`. Set `false` if your own job prunes, connected as a login that may execute `prune_audit_log` |

### The manifest

The manifest is public. A verifying Astrana instance reads it to find your attestation endpoint and to show its owner
who you are. Its keys start with `TRUSTED_ATTESTATION_MANIFEST_`, shortened to `_` in the table. Give each text field as
a JSON object keyed by locale. Where a field has no entry for a locale, the default locale's entry is used. One locale
is enough.

| Field                | Key                   | Required and notes                                                                                                   |
| -------------------- | --------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Organisation name    | `_NAME`               | Required, per locale                                                                                                 |
| Description          | `_DESCRIPTION`        | Optional, per locale                                                                                                 |
| Website              | `_WEBSITE`            | Optional, per locale                                                                                                 |
| Privacy notice       | `_PRIVACY_NOTICE_URL` | Optional, per locale                                                                                                 |
| Support page         | `_SUPPORT_URL`        | Optional, per locale. The page a member who holds no relationship yet is sent to                                     |
| Logo                 | `_LOGO`, `_LOGO_DARK` | Optional. A file path or a `data:` URI, see below. The dark logo needs the light one                                 |
| Jurisdictions        | `_JURISDICTIONS`      | Optional. Comma-separated ISO 3166-1 country or ISO 3166-2 subdivision codes for where you operate or are registered |
| Relationship types   | `_RELATIONSHIP_TYPES` | Required, at least one, comma-separated, from `shared/contract/relationship-types.json`                              |
| Landing page address | `_ENROLLMENT_URL`     | Required. Where members sign in, normally the server's own root address                                              |
| Attestation endpoint | `_ATTESTATION_URL`    | Required. The server's `/api/v1/attest`                                                                              |
| Default locale       | `_DEFAULT_LOCALE`     | `en`                                                                                                                 |
| Offered locales      | `_SUPPORTED_LOCALES`  | Empty, which offers every locale that ships. Private, see Localisation                                               |

The logo is embedded in the manifest as a `data:` URI. Give either the URI itself, or the path to a PNG, JPEG, GIF, WebP
or SVG file, which the server reads and encodes. A path is relative to the application folder unless it is absolute,
such as `/etc/ata/logo.svg` or `C:\ata\logo.svg`. Keep the data URI to 65,536 characters or fewer, about 48 kilobytes of
image, because every verifying Astrana instance downloads it. The manifest schema sets that limit.

The server checks the whole manifest on every request. It refuses every request in any of these cases.

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
2. A `locale` claim from your sign-in service.
3. The browser's Accept-Language list, in full. A region such as `fr-CA` falls back to its language.
4. Your default locale.

`TRUSTED_ATTESTATION_MANIFEST_SUPPORTED_LOCALES`, a comma-separated list, is the ordered list of locales the pages
offer. Leave it empty to offer every locale that ships, in alphabetical order of each language's own name with your
default locale first. The list limits every source above, not only the switcher. The server skips a claim or browser
preference that is not on the list and tries the next source. A listed locale that does not ship is ignored. The
switcher appears only when more than one locale remains. The setting starts with `TRUSTED_ATTESTATION_MANIFEST_` but is
not published in the manifest.

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
the two goes in the matching block of `public/theme-overrides.css`, as its examples show.

Your name and logo come from the manifest. Colours and fonts come from `public/theme-overrides.css`, a short file of CSS
custom properties. It ships as commented examples, each saying what it controls. Uncomment one, change its value, and it
applies the next time a page loads, with no rebuild. Keep the contrast at 4.5:1 for text and 3:1 for large text and
controls. Those are the levels the Web Content Accessibility Guidelines (WCAG) 2.1 AA set, and the pages are built to
meet them.

To change the favicon, replace `public/favicon.svg`. Every `composer install` or `composer sync-shared` copies both
files in again from `shared/` and overwrites your edits. Keep your own copies and put them back after each update. In
the container, mount your copies at `/app/public/theme-overrides.css` and `/app/public/favicon.svg`.

## Step 4. Put it online

PHP has no start-up of its own, so the server checks its settings on every request. In any of these cases it answers
every request with an empty HTTP 500 - Internal Server Error, and writes the reason to `storage/logs/laravel.log` in the
application folder.

- TLS is not configured.
- It cannot reach the database.
- The schema is missing while `TRUSTED_ATTESTATION_CREATE_SCHEMA` is `false`.
- It cannot fetch the OpenID Connect discovery document.
- The manifest fails one of the checks listed under The manifest in Step 3.
- A setting's value cannot be read, such as a word other than `true` or `false` where one is expected, a number outside
  its range, or a text field that is not a JSON object.

Three mistakes only show when the first member signs in. One is a wrong client secret. The others, under SAML, are a
wrong metadata address and a signing key or certificate file that cannot be read, because the server reads them then.

### On shared hosting

Prepare the files on your own computer, from a copy of this repository, either cloned with Git or downloaded as an
archive from the repository's page.

1. Install the dependencies. This also copies the shared contract, schema and interface files into the places the
   application reads them from.

   ```bash
   composer install --no-dev --optimize-autoloader --working-dir php
   ```

2. Write your settings to `php/.env`, with an empty `APP_KEY=` line, then fill the key in.

   ```bash
   php php/artisan key:generate
   ```

3. Upload the whole `php` folder to your hosting account, outside the public web root, for example next to
   `public_html`, or next to `httpdocs` on Plesk.
4. In the control panel, point your subdomain's document root at the uploaded folder's `public` directory, issue the TLS
   certificate for the subdomain, and make the `storage` and `bootstrap/cache` folders writable if the panel does not
   already do so.

On its first request the server creates its tables.

On Windows hosting, IIS serves the site and reads `public/web.config`, which comes with the server and does what
`.htaccess` does under Apache. It needs the URL Rewrite module, which Windows hosting normally provides. Without it, IIS
cannot read the file and answers every request with HTTP 500 - Internal Server Error before the server runs, so nothing
appears in `storage/logs/laravel.log`. If that happens, ask your host to enable URL Rewrite.

To update, run the same `composer install` on a fresh copy, put your edited `public/theme-overrides.css` and
`public/favicon.svg` back, and upload again, keeping your `.env`. After pulling changes into an existing clone,
`composer sync-shared --working-dir php` copies the shared files again, so put the two branding files back after it too.

### On a server of your own

Run the same `composer install` and `key:generate` on the server. Make `php/storage` and `php/bootstrap/cache` writable
by the web server's user. Point Apache, or Nginx with PHP-FPM (FastCGI Process Manager), at `php/public`. The web server
holds the certificate.

On Windows, install PHP for IIS through FastCGI, and install the URL Rewrite module. Point an IIS site at `php\public`,
bind it to your host name over HTTPS with your certificate, and give the site's application pool identity modify rights
on `php\storage` and `php\bootstrap\cache`.

Behind a proxy that holds the certificate instead, set `TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY=true`, set
`TRUSTED_ATTESTATION_TRUSTED_PROXIES` to the proxy's address, and have the proxy set `X-Forwarded-Proto` and
`X-Forwarded-Host` itself and remove any `Forwarded`, `X-Forwarded-Port`, `X-Forwarded-Prefix` or `X-Forwarded-Ssl`
header the client sent. The Caddy and Nginx configurations under [As a container](#as-a-container) set and remove those
headers.

### As a container

Pull the published image, naming the full version of the release you want. Each release has three tags. One is the full
version, such as `1.0.0`. One is the major and minor version, such as `1.0`. The third is `latest`. The image is built
for 64-bit Intel and AMD processors (amd64) only.

```bash
docker pull astrana/ata-php:1.0.0
```

On any other processor, or to build it yourself, build the image from the repository root instead.

```bash
docker build -f php/Dockerfile -t astrana/ata-php:1.0.0 .
```

The published image supports MySQL and PostgreSQL. For SQL Server, build an image with SQL Server support yourself, from
the repository root, by setting the `WITH_SQLSRV` build argument. It adds Microsoft's ODBC Driver 18 for SQL Server and
the `sqlsrv` and `pdo_sqlsrv` extensions. Building it accepts Microsoft's licence terms for the ODBC driver, so read
them first at [aka.ms/odbc18eula](https://aka.ms/odbc18eula). The image holds a copy under `/usr/share/doc/msodbcsql18`.
Then use the name you gave it in place of `astrana/ata-php:1.0.0` in the commands below.

```bash
docker build --build-arg WITH_SQLSRV=true -f php/Dockerfile -t ata-php-sqlsrv .
```

The licences and copyright notices for the third-party software in each image are in `/app/THIRD-PARTY-NOTICES.txt`. The
server also serves that file at `/THIRD-PARTY-NOTICES.txt`, and the licence page links to it.

The image serves with PHP's built-in web server, which suits a container behind a proxy that holds the certificate. It
runs one worker, so it handles one request at a time, and PHP's manual says it is not meant for production use. For
anything beyond a small organisation's traffic, use Apache, Nginx or IIS pointed at `public/`, on a hosting account or a
server of your own.

Put the settings in an environment file. The image holds no certificate, so the file includes
`TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY=true`. Add `LOG_CHANNEL=stderr`, so that `docker logs ata` shows why a
request was refused. Generate the application key with the image itself, then run the container on a port only your
proxy can reach.

```bash
docker run --rm astrana/ata-php:1.0.0 php artisan key:generate --show
docker run -d --name ata --restart unless-stopped --env-file ata.env -p 127.0.0.1:8000:8000 astrana/ata-php:1.0.0
```

Put a TLS-terminating proxy in front. The proxy must set `X-Forwarded-Proto` and `X-Forwarded-Host` itself, from the
request it received, and replace any the client sent. The server trusts these headers when they come from an address in
`TRUSTED_ATTESTATION_TRUSTED_PROXIES`. A client that could set them could choose the scheme and host the server uses to
build its addresses. The server also trusts `X-Forwarded-Port` and `X-Forwarded-Prefix`, so the proxy removes those. It
takes the scheme from `X-Forwarded-Proto` alone and ignores `X-Forwarded-Ssl` and the `Forwarded` header of Request for
Comments (RFC) 7239. The configurations below remove those two as well, so nothing further along can be misled by them.

With Caddy, this is the whole configuration. Caddy obtains and renews the certificate itself, and it sets
`X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-For` from the request it received and overwrites what the
client sent. It leaves the other four headers alone, so the four `header_up` lines remove them.

```
attest.example.org {
    header Strict-Transport-Security "max-age=31536000"
    reverse_proxy 127.0.0.1:8000 {
        header_up -Forwarded
        header_up -X-Forwarded-Port
        header_up -X-Forwarded-Prefix
        header_up -X-Forwarded-Ssl
    }
}
```

With Nginx, terminate TLS in the server block and forward with these lines. `proxy_set_header` replaces a header the
client sent with the proxy's own value, and an empty value removes the header. The last line sends the
Strict-Transport-Security header, because this implementation leaves that header to the proxy.

```nginx
location / {
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header Forwarded "";
    proxy_set_header X-Forwarded-Port "";
    proxy_set_header X-Forwarded-Prefix "";
    proxy_set_header X-Forwarded-Ssl "";
    add_header Strict-Transport-Security "max-age=31536000" always;
}
```

With another proxy, do the same with its own directives. The container must reach your database and your sign-in service
by the addresses in its configuration. The sign-in service must be able to redirect members back to the address your
proxy serves. The demonstration in `php/demo` is a complete working example of a container behind a proxy, with a
database and a sign-in service you would replace with your own.

### Confirm it works

- `https://your-server/.well-known/ata-manifest.json` returns your manifest, with nothing private in it.
- `https://your-server/` offers sign-in through your sign-in service. A member who has been granted nothing sees a page
  saying that nothing has been added for them yet. The page links to your support page, or to your website if you set no
  support page.
- The attestation endpoint answers `{"valid":false}` and nothing else for a key it does not hold. Any 32 bytes in base64
  will do.

```bash
curl -s https://your-server/api/v1/attest -H "Content-Type: application/json" -d '{"public_key":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAE="}'
```

## Step 5. Grant, revoke and extend relationships

A member has no relationships until you grant them. The server has no page and no endpoint for granting, revoking or
extending. You do all three from the database, in the SQL window of your hosting account's database tool, by calling
three procedures the server created. For MySQL that tool is usually phpMyAdmin. For SQL Server it is the control panel's
own database tool, or SQL Server Management Studio.

| Procedure                    | Arguments                                                        | Effect                                                                                                                                                                   |
| ---------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `grant_member_relationship`  | subject identifier, relationship type, subtype or null, actor    | Creates the relationship, with no key yet. Fails if it already exists, if the type is not in the vocabulary, or if the subject identifier has leading or trailing spaces |
| `revoke_member_relationship` | subject identifier, relationship type, actor                     | Marks it revoked. The row stays, so it can be restored. Fails if there is no such relationship                                                                           |
| `extend_member_relationship` | subject identifier, relationship type, new expiry or null, actor | Clears a revocation, sets or clears the expiry, restores an expired relationship. Fails if there is none                                                                 |

The subject identifier is the value of the subject claim you configured, which is the member's email address if you
followed Step 1. The actor is free text naming who made the change, and goes into the audit log. Run each of the
examples below on its own, when you need it.

To grant a membership:

```sql
CALL grant_member_relationship('pat@example.org', 'member', NULL, 'membership secretary');
```

To set or move its expiry:

```sql
CALL extend_member_relationship('pat@example.org', 'member', '2027-04-01', 'membership secretary');
```

To revoke it:

```sql
CALL revoke_member_relationship('pat@example.org', 'member', 'membership secretary');
```

Four rules apply to the calls.

- On MySQL and PostgreSQL, call the procedures with `CALL`. On SQL Server, use `EXEC` with the same arguments and no
  brackets, as in `EXEC grant_member_relationship 'pat@example.org', 'member', NULL, 'membership secretary'`.
- MySQL requires every argument, null included.
- Call each procedure on its own, outside any transaction your tooling opens. On MySQL a procedure starts its own
  transaction, which commits any transaction already open.
- An expiry is a moment in Coordinated Universal Time (UTC). A date on its own means midnight at the start of that day,
  so the expiry example above keeps the membership valid through 31 March. On PostgreSQL the expiry carries a time zone,
  and a value written without an offset is read in the session's time zone, so give the offset, as in
  `'2027-04-01 00:00:00+00'`.

When the member signs in, the relationship is there, waiting for their key.

<p>
  <img src="../screenshots/demo-me-page-key-unset-relationship.png" alt="The self-service page after a grant. One relationship card, marked Waiting For Your Key, with an empty field for the attestation key and a Save Key button, in light mode." width="49%">
  <img src="../screenshots/demo-me-page-key-unset-relationship-darkmode.png" alt="The self-service page after a grant. One relationship card, marked Waiting For Your Key, with an empty field for the attestation key and a Save Key button, in dark mode." width="49%">
</p>

A member whose relationship is revoked can still sign in and register a key. The relationship stays revoked until you
extend it. A member can revoke a relationship themselves, which only you can undo. A member can also remove a
relationship entirely, after which only a new grant brings it back.

If membership renews yearly, grant the relationship, then set its expiry with `extend_member_relationship`, and call it
again with the next expiry at each renewal. Once the expiry passes, the membership shows as expired, with nothing more
for you to do.

## Keeping it running

- Back up the database with your hosting account's backups. Everything the server holds is in its two tables.
- If you use an uptime monitor, point it at `/.well-known/ata-manifest.json`. It needs no sign-in. A server that answers
  it passed every check a request runs, apart from the SAML metadata, which is fetched at the first sign-in.
- A Microsoft client secret expires after two years at most, and sign-in stops working when it does. Note the date and
  create a new secret before then.
- Every grant, revoke, extension, key registration, key clearing, removal and member's own revoke is in the audit log,
  with the member's subject identifier. For the organisation's own changes, the log also holds the name you gave as the
  actor. Keys and attestation lookups are never written to it.
- New versions come as new releases of this implementation. A release that changes the schema says so in its notes, and
  you apply the change by hand. Equal major and minor versions mean the three implementations answer the same on
  everything the contract and the shared interface define.
- Report a security problem privately, as [SECURITY.md](../../SECURITY.md) describes.
