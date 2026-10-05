package org.astrana.trustedattestation.config;

import jakarta.validation.constraints.Max;
import jakarta.validation.constraints.Min;
import jakarta.validation.constraints.NotBlank;
import java.util.List;
import java.util.Map;
import org.springframework.boot.context.properties.ConfigurationProperties;
import org.springframework.validation.annotation.Validated;

/**
 * Everything IT staff configure. Deployment is three things (decision records 1, 12 and 16 in docs/adr): deploy the
 * application, create an empty database, and set the database and identity system connection details. The
 * application creates its own schema on first run.
 *
 * <p>The datasource itself is Spring's own {@code spring.datasource.*}. Only what is specific to Astrana
 * Trusted Attestation lives here.
 */
@ConfigurationProperties(prefix = "trusted-attestation")
@Validated
public class TrustedAttestationProperties {

    private Database database = new Database();
    private Iam iam = new Iam();
    private Tls tls = new Tls();
    private Audit audit = new Audit();
    private Manifest manifest = new Manifest();

    public Database getDatabase() {
        return database;
    }

    public void setDatabase(Database database) {
        this.database = database;
    }

    public Iam getIam() {
        return iam;
    }

    public void setIam(Iam iam) {
        this.iam = iam;
    }

    public Tls getTls() {
        return tls;
    }

    public void setTls(Tls tls) {
        this.tls = tls;
    }

    public Audit getAudit() {
        return audit;
    }

    public void setAudit(Audit audit) {
        this.audit = audit;
    }

    public Manifest getManifest() {
        return manifest;
    }

    public void setManifest(Manifest manifest) {
        this.manifest = manifest;
    }

    /** Which engine's DDL to apply, and whether to apply it at all. */
    public enum Provider {
        SQL_SERVER("mssql"),
        POSTGRESQL("postgres"),
        MYSQL("mysql");

        private final String scriptSuffix;

        Provider(String scriptSuffix) {
            this.scriptSuffix = scriptSuffix;
        }

        public String scriptSuffix() {
            return scriptSuffix;
        }

        /** How each engine names the schema the connection is currently pointed at. */
        public String currentSchemaExpression() {
            return switch (this) {
                case SQL_SERVER -> "SCHEMA_NAME()";
                case POSTGRESQL -> "current_schema()";
                case MYSQL -> "DATABASE()";
            };
        }
    }

    public static class Database {
        private Provider provider = Provider.POSTGRESQL;

        /**
         * Whether the application creates its own schema on first run. An organisation whose database
         * administrator applies {@code shared/schema/*.sql} by hand can turn this off. The application then
         * refuses to start if the schema is missing rather than silently creating it.
         */
        private boolean createSchemaOnStartup = true;

        public Provider getProvider() {
            return provider;
        }

        public void setProvider(Provider provider) {
            this.provider = provider;
        }

        public boolean isCreateSchemaOnStartup() {
            return createSchemaOnStartup;
        }

        public void setCreateSchemaOnStartup(boolean createSchemaOnStartup) {
            this.createSchemaOnStartup = createSchemaOnStartup;
        }
    }

    /** Which protocol the organisation's identity system speaks. A deployment picks one, and both are wired. */
    public enum Protocol {
        OIDC,
        SAML,
    }

    public static class Iam {
        /**
         * OIDC or SAML. Astrana Trusted Attestation speaks the protocol, not the vendor, so this is the
         * only thing that changes between an organisation on Entra ID and an organisation on Active
         * Directory Federation Services.
         */
        private Protocol protocol = Protocol.OIDC;

        /**
         * Claim carrying the member's {@code iam_subject_id}. OIDC {@code sub} by default.
         *
         * <p>Under SAML this names a SAML attribute instead, and falls back to the assertion's NameID
         * when no attribute of that name is present, which is the usual case, since NameID is SAML's
         * equivalent of {@code sub}.
         */
        private String subjectClaim = "sub";

        /** Claim carrying the display name, shown on the self-service page only. Never stored. */
        private String nameClaim = "name";

        public Protocol getProtocol() {
            return protocol;
        }

        public void setProtocol(Protocol protocol) {
            this.protocol = protocol;
        }

        public String getSubjectClaim() {
            return subjectClaim;
        }

        public void setSubjectClaim(String subjectClaim) {
            this.subjectClaim = subjectClaim;
        }

        public String getNameClaim() {
            return nameClaim;
        }

        public void setNameClaim(String nameClaim) {
            this.nameClaim = nameClaim;
        }
    }

    public static class Tls {
        /**
         * Set when a reverse proxy terminates TLS in front of the application, the normal shape for a
         * Spring Boot application behind Nginx, or a web archive in an existing Tomcat. It must be set by
         * hand. There is no auto-detection, and no default that lets an unconfigured instance serve plain
         * HTTP.
         */
        private boolean terminatedByProxy;

        /**
         * Whether to send Strict-Transport-Security. On by default, and turned off in the dev profile,
         * because a browser that accepts HSTS for localhost applies it to every port on localhost, so
         * leaving it on in development breaks unrelated http applications on the same machine. ASP.NET
         * Core skips loopback for the same reason. An operator who sets a stronger policy at the proxy turns
         * it off, so the browser sees one header and not two.
         */
        private boolean hstsEnabled = true;

        public boolean isTerminatedByProxy() {
            return terminatedByProxy;
        }

        public boolean isHstsEnabled() {
            return hstsEnabled;
        }

        public void setHstsEnabled(boolean hstsEnabled) {
            this.hstsEnabled = hstsEnabled;
        }

        public void setTerminatedByProxy(boolean terminatedByProxy) {
            this.terminatedByProxy = terminatedByProxy;
        }
    }

    public static class Audit {
        /**
         * Audit logging itself is mandatory and not configurable. This turns off only the built-in prune
         * job, for an organisation that runs its own job to prune the audit log, and the tests use it to run
         * without a scheduler.
         */
        private boolean pruneEnabled = true;

        /** Default 5 years. Regulated organisations may have longer or shorter legal obligations. */
        @Min(1) @Max(36_500) private int retentionDays = 1825;

        /**
         * When the prune runs, as a Spring cron expression. Daily at 02:00 server time by default:
         * retention is measured in years, so there is no need for finer timing, and a quiet hour keeps
         * the job clear of the working day.
         */
        private String cron = "0 0 2 * * *";

        public boolean isPruneEnabled() {
            return pruneEnabled;
        }

        public void setPruneEnabled(boolean pruneEnabled) {
            this.pruneEnabled = pruneEnabled;
        }

        public int getRetentionDays() {
            return retentionDays;
        }

        public void setRetentionDays(int retentionDays) {
            this.retentionDays = retentionDays;
        }

        public String getCron() {
            return cron;
        }

        public void setCron(String cron) {
            this.cron = cron;
        }
    }

    public static class Manifest {
        private int manifestVersion = 1;

        @NotBlank private String defaultLocale = "en";

        /**
         * The locales the organisation offers on the landing page and the self-service page, in order, the
         * first being the organisation's primary. Empty means every locale the application ships strings
         * for. Configured values with no shipped strings are ignored. The setting limits every source the
         * locale is resolved from, and the language switcher appears only when more than one locale remains.
         */
        private List<String> supportedLocales = List.of();

        private Map<String, String> name = Map.of();
        private Map<String, String> description = Map.of();
        private Map<String, String> website = Map.of();

        /**
         * Optional. Where a member who has been granted nothing yet is pointed for help, a contact or
         * support page. Locale-keyed like the other URLs. The self-service page links to it when it lists
         * nothing, falling back to {@link #website} when it is unset, and to plain text when neither is set.
         */
        private Map<String, String> supportUrl = Map.of();

        /**
         * Optional. Either a path to an image file or a data URI, and the manifest always carries the
         * encoded form. Not locale-keyed, because a logo has no text to translate. This is the light-mode
         * and default logo, the one rendered whenever no dark preference applies.
         */
        private String logoData;

        /**
         * Optional dark-mode logo, in the same form as {@link #logoData} (a path or a data URI). When set,
         * a member whose device prefers a dark colour scheme sees this, and everyone else sees {@link
         * #logoData}. Only meaningful alongside a light logo, which stays the fallback the manifest and the
         * page render when no scheme preference applies, so a dark logo without a light one is a
         * configuration error.
         */
        private String logoDataDark;

        private Map<String, String> privacyNoticeUrl = Map.of();

        /**
         * Optional. ISO 3166-1 alpha-2 country codes, plus ISO 3166-2 subdivision codes, for where the
         * organisation operates or is registered.
         */
        private List<String> jurisdictions = List.of();

        /** The subset of the governed vocabulary this organisation actually issues. */
        private List<String> relationshipTypes = List.of();

        @NotBlank private String enrollmentUrl = "";

        @NotBlank private String attestationUrl = "";

        public int getManifestVersion() {
            return manifestVersion;
        }

        public void setManifestVersion(int manifestVersion) {
            this.manifestVersion = manifestVersion;
        }

        public String getDefaultLocale() {
            return defaultLocale;
        }

        public void setDefaultLocale(String defaultLocale) {
            this.defaultLocale = defaultLocale;
        }

        public List<String> getSupportedLocales() {
            return supportedLocales;
        }

        public void setSupportedLocales(List<String> supportedLocales) {
            this.supportedLocales = supportedLocales;
        }

        public Map<String, String> getName() {
            return name;
        }

        public void setName(Map<String, String> name) {
            this.name = name;
        }

        public Map<String, String> getDescription() {
            return description;
        }

        public void setDescription(Map<String, String> description) {
            this.description = description;
        }

        public Map<String, String> getWebsite() {
            return website;
        }

        public void setWebsite(Map<String, String> website) {
            this.website = website;
        }

        public Map<String, String> getSupportUrl() {
            return supportUrl;
        }

        public void setSupportUrl(Map<String, String> supportUrl) {
            this.supportUrl = supportUrl;
        }

        public String getLogoData() {
            return logoData;
        }

        public void setLogoData(String logoData) {
            this.logoData = logoData;
        }

        public String getLogoDataDark() {
            return logoDataDark;
        }

        public void setLogoDataDark(String logoDataDark) {
            this.logoDataDark = logoDataDark;
        }

        public Map<String, String> getPrivacyNoticeUrl() {
            return privacyNoticeUrl;
        }

        public void setPrivacyNoticeUrl(Map<String, String> privacyNoticeUrl) {
            this.privacyNoticeUrl = privacyNoticeUrl;
        }

        public List<String> getJurisdictions() {
            return jurisdictions;
        }

        public void setJurisdictions(List<String> jurisdictions) {
            this.jurisdictions = jurisdictions;
        }

        public List<String> getRelationshipTypes() {
            return relationshipTypes;
        }

        public void setRelationshipTypes(List<String> relationshipTypes) {
            this.relationshipTypes = relationshipTypes;
        }

        public String getEnrollmentUrl() {
            return enrollmentUrl;
        }

        public void setEnrollmentUrl(String enrollmentUrl) {
            this.enrollmentUrl = enrollmentUrl;
        }

        public String getAttestationUrl() {
            return attestationUrl;
        }

        public void setAttestationUrl(String attestationUrl) {
            this.attestationUrl = attestationUrl;
        }
    }
}
