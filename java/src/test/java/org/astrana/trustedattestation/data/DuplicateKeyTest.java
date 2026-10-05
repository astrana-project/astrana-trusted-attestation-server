package org.astrana.trustedattestation.data;

import static org.assertj.core.api.Assertions.assertThat;

import java.sql.SQLException;
import org.junit.jupiter.api.Test;
import org.springframework.dao.DataIntegrityViolationException;

/**
 * Which database failures mean "that key is already registered", and which do not.
 *
 * <p>Spring folds every integrity violation into one exception class, so the rule reads the engine's own
 * error report underneath it: PostgreSQL's unique_violation state, and MySQL's and SQL Server's duplicate
 * error numbers, which both report under the shared 23000 state. A violation of anything else is left to
 * surface as the fault it is.
 */
class DuplicateKeyTest {

    private static DataIntegrityViolationException reported(String sqlState, int errorCode) {
        return new DataIntegrityViolationException(
                "could not execute statement",
                new RuntimeException(
                        "wrapped by the persistence layer", new SQLException("refused", sqlState, errorCode)));
    }

    @Test
    void postgresUniqueViolationIsADuplicateKey() {
        assertThat(DuplicateKey.describes(reported("23505", 0))).isTrue();
    }

    @Test
    void mysqlAndSqlServerDuplicateErrorNumbersAreADuplicateKeyUnderTheSharedState() {
        assertThat(DuplicateKey.describes(reported("23000", 1062))).isTrue();
        assertThat(DuplicateKey.describes(reported("23000", 2601))).isTrue();
        assertThat(DuplicateKey.describes(reported("23000", 2627))).isTrue();
    }

    @Test
    void anotherIntegrityViolationIsNotADuplicateKey() {
        // A not-null violation (PostgreSQL 23502, MySQL 1048, SQL Server 515) is a different fault, and
        // answering it as a conflict would hide it behind a plausible message.
        assertThat(DuplicateKey.describes(reported("23502", 0))).isFalse();
        assertThat(DuplicateKey.describes(reported("23000", 1048))).isFalse();
        assertThat(DuplicateKey.describes(reported("23000", 515))).isFalse();
    }

    @Test
    void aViolationWithNoDatabaseErrorUnderneathIsNotADuplicateKey() {
        assertThat(DuplicateKey.describes(new DataIntegrityViolationException("uq_public_key")))
                .isFalse();
    }
}
