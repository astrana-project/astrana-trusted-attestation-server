package org.astrana.trustedattestation.service;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatCode;

import jakarta.persistence.EntityManager;
import jakarta.persistence.Query;
import java.lang.reflect.Field;
import java.util.ArrayList;
import java.util.List;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Provider;
import org.junit.jupiter.api.Test;

/**
 * Pruning that nobody has to remember to set up.
 *
 * <p>Worth testing carefully because every way it can go wrong is silent. A prune that never fires
 * leaves the audit log growing without limit, and retention length is what scales breach exposure. One
 * that takes the application down over housekeeping is worse. Neither shows up as a failing request;
 * the log just quietly does the wrong thing for months.
 *
 * <p>No database and no Spring context: the procedure call is intercepted and inspected. What is being
 * tested is what gets called and with what, not what the procedure does with it.
 * shared/test/role-checks.py covers that against all three engines.
 */
class AuditPruneServiceTest {

    /** One recorded call: the statement, and what was bound into it. */
    private record Call(String sql, List<Object> parameters) {}

    /**
     * The smallest EntityManager that can answer this service, recording what it was asked to run.
     *
     * <p>Hand-written rather than mocked with a framework: the service touches two methods, and a
     * literal recorder is easier to read than the expectations that would replace it.
     */
    private static final class Recorder implements java.lang.reflect.InvocationHandler {
        private final List<Call> calls = new ArrayList<>();
        private final RuntimeException failWith;

        Recorder(RuntimeException failWith) {
            this.failWith = failWith;
        }

        @Override
        public Object invoke(Object proxy, java.lang.reflect.Method method, Object[] args) {
            if (method.getName().equals("createNativeQuery")) {
                Call call = new Call((String) args[0], new ArrayList<>());
                calls.add(call);

                return java.lang.reflect.Proxy.newProxyInstance(
                        Query.class.getClassLoader(),
                        new Class<?>[] {Query.class},
                        (queryProxy, queryMethod, queryArgs) -> switch (queryMethod.getName()) {
                            case "setParameter" -> {
                                call.parameters().add(queryArgs[1]);
                                yield queryProxy;
                            }
                            case "executeUpdate" -> {
                                if (failWith != null) {
                                    throw failWith;
                                }
                                yield 0;
                            }
                            default -> null;
                        });
            }

            return null;
        }
    }

    private static List<Call> pruneWith(
            Provider provider, boolean enabled, int retentionDays, RuntimeException failWith) {
        TrustedAttestationProperties properties = new TrustedAttestationProperties();
        properties.getDatabase().setProvider(provider);
        properties.getAudit().setPruneEnabled(enabled);
        properties.getAudit().setRetentionDays(retentionDays);

        AuditPruneService service = new AuditPruneService(properties);

        Recorder recorder = new Recorder(failWith);
        EntityManager entityManager = (EntityManager) java.lang.reflect.Proxy.newProxyInstance(
                EntityManager.class.getClassLoader(), new Class<?>[] {EntityManager.class}, recorder);

        // The field is injected by the container in production. Set directly here so this stays a unit
        // test: bringing up a context to reach one field would make it a slow test of Spring instead.
        try {
            Field field = AuditPruneService.class.getDeclaredField("entityManager");
            field.setAccessible(true);
            field.set(service, entityManager);
        } catch (ReflectiveOperationException problem) {
            throw new IllegalStateException("AuditPruneService no longer has an entityManager field", problem);
        }

        service.prune();

        return recorder.calls;
    }

    private static List<Call> pruneWith(Provider provider) {
        return pruneWith(provider, true, 1825, null);
    }

    // ---------------------------------------------------------------------------------------------
    // What it runs
    // ---------------------------------------------------------------------------------------------

    @Test
    void itCallsTheProcedureTheWayEachEngineSpellsIt() {
        // The three engines differ here and the procedure does not. Getting this wrong means pruning
        // silently never works on one engine, which nothing else would reveal.
        assertThat(pruneWith(Provider.POSTGRESQL).getFirst().sql()).contains("CALL prune_audit_log");
        assertThat(pruneWith(Provider.MYSQL).getFirst().sql()).contains("CALL prune_audit_log");
        assertThat(pruneWith(Provider.SQL_SERVER).getFirst().sql()).contains("EXEC prune_audit_log");
    }

    @Test
    void itPassesTheConfiguredRetentionToTheProcedure() {
        assertThat(pruneWith(Provider.POSTGRESQL, true, 30, null).getFirst().parameters())
                .containsExactly(30);
    }

    @Test
    void theLogicStaysInTheDatabase() {
        // Only a procedure call, never a DELETE composed here. Retention is one of the things the
        // database owns, same as revoke and extend; only the schedule lives in the application.
        assertThat(pruneWith(Provider.POSTGRESQL).getFirst().sql().toUpperCase())
                .doesNotContain("DELETE");
    }

    // ---------------------------------------------------------------------------------------------
    // When it does not run
    // ---------------------------------------------------------------------------------------------

    @Test
    void itRunsNothingAtAllWhenPruningIsTurnedOff() {
        // An organisation running its own scheduler turns this off so the two do not both run.
        assertThat(pruneWith(Provider.POSTGRESQL, false, 1825, null)).isEmpty();
    }

    // ---------------------------------------------------------------------------------------------
    // When it goes wrong
    // ---------------------------------------------------------------------------------------------

    @Test
    void aFailingPruneDoesNotPropagate() {
        // Housekeeping must not take the application down. The scheduler would log the exception and
        // carry on, but a @Transactional method that throws also rolls back -- and there is nothing here
        // worth rolling back that is worth an alert either. The next run tries again.
        assertThatCode(() -> pruneWith(Provider.POSTGRESQL, true, 1825, new RuntimeException("the database went away")))
                .doesNotThrowAnyException();
    }

    @Test
    void aFailingPruneStillAttemptedTheCall() {
        // Distinguishes "it swallowed the failure" from "it never tried" -- both look identical from
        // outside, and only one of them is the behaviour intended here.
        assertThat(pruneWith(Provider.POSTGRESQL, true, 1825, new RuntimeException("the database went away")))
                .hasSize(1);
    }
}
