package org.astrana.trustedattestation.config;

import static org.assertj.core.api.Assertions.assertThat;

import java.util.Properties;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.config.YamlPropertiesFactoryBean;
import org.springframework.core.io.ClassPathResource;

/**
 * Pins the Hibernate JDBC time zone to UTC.
 *
 * <p>Load-bearing on MySQL and SQL Server, whose timestamp columns are timezone-naive (DATETIME /
 * DATETIME2) and which the stored procedures write as UTC wall-clock. Without this setting Hibernate
 * converts {@code Instant} to and from those columns through the JVM's default zone, so on a non-UTC host
 * {@code expires_at} / {@code revoked_at} come back shifted and a relationship's status is computed against
 * the wrong instant -- while the .NET and PHP implementations read the same naive value as UTC literally.
 * The conformance matrix runs Java only against PostgreSQL (TIMESTAMPTZ, immune), so it cannot catch a
 * regression here; this test can.
 */
class HibernateTimeZoneConfigTest {

    @Test
    void timestampsAreReadAndWrittenAsUtcRegardlessOfJvmZone() {
        YamlPropertiesFactoryBean yaml = new YamlPropertiesFactoryBean();
        yaml.setResources(new ClassPathResource("application.yml"));
        Properties properties = yaml.getObject();

        assertThat(properties).isNotNull();
        assertThat(properties.getProperty("spring.jpa.properties.hibernate.jdbc.time_zone"))
                .isEqualTo("UTC");
    }
}
