package org.astrana.trustedattestation.data;

import java.sql.SQLException;
import java.util.Set;
import org.springframework.dao.DataAccessException;

/**
 * Whether a database failure is the unique index refusing a key that is already taken.
 *
 * <p>Two members can submit the same key at the same moment. The check before the write settles the
 * ordinary sequential case, but it cannot settle the simultaneous one: both requests read an unused key,
 * both proceed, and the unique index is what decides. Whichever loses gets a constraint violation, and
 * that member is told their key is already registered, HTTP 409, rather than handed a 500.
 *
 * <p>Matched on the engine's own error number, the same rule the PHP implementation applies, not on
 * Spring's exception class. Spring translates every integrity violation to one
 * {@code DataIntegrityViolationException}, so a class match alone would answer "that key is already
 * registered" to a member whose write failed for a different reason, hiding a real fault behind a plausible
 * answer. Anything that is not the duplicate is left to surface as the 500 it is.
 */
public final class DuplicateKey {

    /** PostgreSQL: unique_violation has a SQLSTATE of its own. */
    private static final String POSTGRES_UNIQUE_VIOLATION = "23505";

    /**
     * MySQL and SQL Server report every integrity violation as SQLSTATE 23000, so the engine's error number
     * narrows it: MySQL's ER_DUP_ENTRY, and SQL Server's duplicate on an index and duplicate on a constraint.
     */
    private static final Set<Integer> DRIVER_DUPLICATE_CODES = Set.of(1062, 2601, 2627);

    private DuplicateKey() {}

    public static boolean describes(DataAccessException exception) {
        for (Throwable cause = exception; cause != null; cause = cause.getCause()) {
            if (cause instanceof SQLException sql && describes(sql)) {
                return true;
            }

            if (cause.getCause() == cause) {
                break;
            }
        }

        return false;
    }

    private static boolean describes(SQLException exception) {
        return POSTGRES_UNIQUE_VIOLATION.equals(exception.getSQLState())
                || DRIVER_DUPLICATE_CODES.contains(exception.getErrorCode());
    }
}
