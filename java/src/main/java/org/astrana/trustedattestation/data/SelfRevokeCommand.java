package org.astrana.trustedattestation.data;

import javax.sql.DataSource;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Provider;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Component;

/**
 * Calls {@code member_self_revoke_relationship}.
 *
 * <p>Through the procedure rather than by writing {@code revoked_at} directly, because the procedure is
 * what enforces the direction: NULL to now(), and never the reverse. A column grant cannot express
 * "settable once, never clearable", so the application is never given the ability to lift a revocation
 * at all -- only the organisation can, through {@code extend_member_relationship}, which this
 * application does not call and its database principal cannot execute.
 *
 * <p>The procedure writes its own audit row, so nothing here does. Doing both would put two entries in
 * an append-only log for one event, and the log cannot be corrected afterwards.
 *
 * <p>Called through a plain {@link JdbcTemplate}, in autocommit, rather than through the JPA
 * {@code EntityManager} -- and deliberately with no surrounding {@code @Transactional}. The procedure
 * opens and commits its own transaction (MySQL and SQL Server need that; see the schema), and a
 * procedure that commits inside an outer transaction ends it, so a JPA-managed transaction here would
 * leave the framework committing a transaction that is already gone -- a 500 for a revoke that
 * succeeded. JPA's {@code executeUpdate} also refuses to run at all without an active transaction, so
 * the two cannot be reconciled. Raw JDBC in autocommit lets the procedure be the sole transaction, which
 * is exactly how the other two implementations call it.
 */
@Component
public class SelfRevokeCommand {

    private final JdbcTemplate jdbc;
    private final Provider provider;

    public SelfRevokeCommand(DataSource dataSource, TrustedAttestationProperties properties) {
        this.jdbc = new JdbcTemplate(dataSource);
        this.provider = properties.getDatabase().getProvider();
    }

    public void invoke(String iamSubjectId, String relationshipType) {
        // Parameterised, not concatenated: only the call syntax varies by engine, never the values. SQL
        // Server spells a procedure call differently from the other two, and MySQL's procedures declare
        // no defaults, so every argument is passed explicitly everywhere.
        String call = provider == Provider.SQL_SERVER
                ? "EXEC member_self_revoke_relationship ?, ?"
                : "CALL member_self_revoke_relationship(?, ?)";

        jdbc.update(call, iamSubjectId, relationshipType);
    }
}
