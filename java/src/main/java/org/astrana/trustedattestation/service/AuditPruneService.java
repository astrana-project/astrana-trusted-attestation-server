package org.astrana.trustedattestation.service;

import jakarta.persistence.EntityManager;
import jakarta.persistence.PersistenceContext;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Provider;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

/**
 * Runs {@code prune_audit_log} once a day, at a configured time.
 *
 * <p>Pruning is on by default rather than left to the host to remember. Retention being "the host's
 * decision" only protects anyone if the decision actually gets made, and the smaller orgs least likely to
 * have a compliance team are exactly the ones most likely to never prune at all -- which is backwards,
 * since retention length is what scales breach exposure.
 *
 * <p>Scheduled here rather than with SQL Server Agent, the MySQL event scheduler or pg_cron: availability
 * varies too much by engine and hosting tier to be a reliable default. {@code @Scheduled} is part of the
 * framework already, runs inside the same process as the app, and works identically whichever of the
 * three databases an org picked. Nothing extra is deployed -- {@code java -jar app.jar} is still the only
 * thing that runs.
 */
@Service
public class AuditPruneService {

    private static final Logger log = LoggerFactory.getLogger(AuditPruneService.class);

    @PersistenceContext
    private EntityManager entityManager;

    private final TrustedAttestationProperties properties;

    public AuditPruneService(TrustedAttestationProperties properties) {
        this.properties = properties;
    }

    /**
     * Daily, at {@code trusted-attestation.audit.cron}. Given retention is measured in years there is no need for finer
     * timing: daily keeps enforcement tight relative to the retention window while keeping the job itself
     * infrequent and cheap.
     */
    @Scheduled(cron = "${trusted-attestation.audit.cron:0 0 2 * * *}")
    @Transactional
    public void prune() {
        if (!properties.getAudit().isPruneEnabled()) {
            log.warn("Audit pruning is disabled. Audit rows will accumulate indefinitely, and retention "
                    + "length directly scales how much history a database breach would expose.");
            return;
        }

        int retentionDays = properties.getAudit().getRetentionDays();

        try {
            // The logic itself stays in the database, same as revoke and extend. Only the schedule that
            // triggers it lives in the application. The three engines spell a procedure call differently;
            // the procedure is identical.
            String call = properties.getDatabase().getProvider() == Provider.SQL_SERVER
                    ? "EXEC prune_audit_log @retention_days = ?1"
                    : "CALL prune_audit_log(?1)";

            entityManager.createNativeQuery(call).setParameter(1, retentionDays).executeUpdate();

            log.info("Audit prune completed (retention {} days).", retentionDays);
        } catch (RuntimeException exception) {
            // A failed prune must not take the app down: the member-facing and verification paths are
            // unaffected by it, and the next run will try again.
            log.error("Audit prune failed. It will be retried at the next scheduled run.", exception);
        }
    }
}
