package org.astrana.trustedattestation.data;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.contains;
import static org.mockito.Mockito.atLeastOnce;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.time.Duration;
import java.util.ArrayDeque;
import java.util.Deque;
import java.util.Iterator;
import java.util.List;
import javax.sql.DataSource;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Provider;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

/**
 * What the first-run schema check decides, against a scripted connection.
 *
 * <p>The decisions: a complete schema is left alone, including one seen through a login holding only the
 * application role, a schema with some of the objects the application uses is refused with a message naming
 * what is missing, an empty database is created or refused according to the setting, and an
 * instance that loses the race to create the schema keeps checking, carries on when the winner has
 * finished, and gives up when the wait runs out.
 */
class SchemaInitializerTest {

    /** Every object the script creates, as the administrator who applied it sees them. */
    private static final List<String> EVERYTHING = List.of(
            "member_relationships",
            "audit_log",
            "grant_member_relationship",
            "revoke_member_relationship",
            "extend_member_relationship",
            "member_self_revoke_relationship",
            "prune_audit_log");

    /** The two procedures a login holding only the application role can see. */
    private static final List<String> APPLICATION_PROCEDURES =
            List.of("member_self_revoke_relationship", "prune_audit_log");

    private final DataSource dataSource = mock(DataSource.class);
    private final Connection connection = mock(Connection.class);
    private final Statement statement = mock(Statement.class);
    private final TrustedAttestationProperties properties = new TrustedAttestationProperties();

    /** The names each successive existence query answers with: tables first, then procedures. */
    private final Deque<List<String>> answers = new ArrayDeque<>();

    @BeforeEach
    void connect() throws SQLException {
        when(dataSource.getConnection()).thenReturn(connection);
        when(connection.createStatement()).thenReturn(statement);
        when(statement.executeQuery(anyString())).thenAnswer(invocation -> resultSetOf(answers.remove()));
        properties.getDatabase().setProvider(Provider.POSTGRESQL);
    }

    private static ResultSet resultSetOf(List<String> names) throws SQLException {
        ResultSet results = mock(ResultSet.class);
        Iterator<String> remaining = names.iterator();
        String[] current = new String[1];
        when(results.next()).thenAnswer(invocation -> {
            if (!remaining.hasNext()) {
                return false;
            }
            current[0] = remaining.next();
            return true;
        });
        when(results.getString(1)).thenAnswer(invocation -> current[0]);
        return results;
    }

    private void schemaHolds(List<String> tables, List<String> procedures) {
        answers.add(tables);
        answers.add(procedures);
    }

    /** The production race wait, with no pause between looks, so a test that settles never sleeps. */
    private SchemaInitializer initializer() {
        return new SchemaInitializer(dataSource, properties, SchemaInitializer.RACE_WAIT, Duration.ZERO);
    }

    /** The answers a race keeps giving while the winner is part-way through: one table and nothing else. */
    private void schemaStaysIncomplete(int looks) {
        for (int look = 0; look < looks; look++) {
            schemaHolds(List.of("member_relationships"), List.of());
        }
    }

    private void createMeetsAnObjectAnotherInstanceMade() throws SQLException {
        when(statement.execute(anyString()))
                .thenThrow(new SQLException("relation \"member_relationships\" already exists", "42P07"));
    }

    @Test
    void aCompleteSchemaIsLeftAlone() throws SQLException {
        schemaHolds(EVERYTHING.subList(0, 2), EVERYTHING.subList(2, 7));

        initializer().initialize();

        verify(statement, never()).execute(anyString());
    }

    @Test
    void aSchemaSeenThroughTheApplicationRoleIsLeftAlone() throws SQLException {
        // A login holding only the application role sees the two tables and only the two procedures it may
        // run. The administrator's procedures are there but invisible to it, and that is a complete schema.
        schemaHolds(EVERYTHING.subList(0, 2), APPLICATION_PROCEDURES);

        initializer().initialize();

        verify(statement, never()).execute(anyString());
    }

    @Test
    void theProbeAsksOnlyForTheProceduresTheApplicationRuns() throws SQLException {
        schemaHolds(EVERYTHING.subList(0, 2), APPLICATION_PROCEDURES);

        initializer().initialize();

        verify(statement)
                .executeQuery(contains("routine_name IN ('member_self_revoke_relationship', 'prune_audit_log')"));
    }

    @Test
    void aPartialSchemaIsRefusedNamingWhatIsMissing() {
        // The first table is there and nothing else, which is exactly the shape a check of that table
        // alone would have accepted.
        schemaHolds(List.of("member_relationships"), List.of());
        SchemaInitializer initializer = initializer();

        assertThatThrownBy(initializer::initialize)
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("incomplete")
                .hasMessageContaining("audit_log")
                .hasMessageContaining("prune_audit_log")
                .hasMessageNotContaining("member_relationships,");
    }

    @Test
    void anEmptyDatabaseIsCreatedFromThePackagedScript() throws SQLException {
        schemaHolds(List.of(), List.of());

        initializer().initialize();

        verify(statement, atLeastOnce()).execute(contains("CREATE TABLE member_relationships"));
        verify(statement, atLeastOnce()).execute(contains("prune_audit_log"));
    }

    @Test
    void anEmptyDatabaseIsRefusedWhenCreationIsTurnedOff() throws SQLException {
        properties.getDatabase().setCreateSchemaOnStartup(false);
        schemaHolds(List.of(), List.of());
        SchemaInitializer initializer = initializer();

        assertThatThrownBy(initializer::initialize)
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("create-schema-on-startup");
        verify(statement, never()).execute(anyString());
    }

    @Test
    void anInstanceThatLosesTheRaceToCreateTheSchemaChecksAgainAndContinues() throws SQLException {
        // Empty on the first look, then the CREATE meets a table another instance just made, and the second
        // look finds everything there.
        schemaHolds(List.of(), List.of());
        createMeetsAnObjectAnotherInstanceMade();
        schemaHolds(EVERYTHING.subList(0, 2), EVERYTHING.subList(2, 7));

        initializer().initialize();

        assertThat(answers).isEmpty();
    }

    @Test
    void anInstanceThatLosesTheRaceKeepsCheckingUntilTheWinnerHasFinished() throws SQLException {
        // The winner is still part-way through the script at the first two looks, and done at the third.
        schemaHolds(List.of(), List.of());
        createMeetsAnObjectAnotherInstanceMade();
        schemaStaysIncomplete(2);
        schemaHolds(EVERYTHING.subList(0, 2), EVERYTHING.subList(2, 7));

        initializer().initialize();

        assertThat(answers).isEmpty();
    }

    @Test
    void anInstanceThatLosesTheRaceToASchemaThatStaysIncompleteIsRefusedOnceTheWaitRunsOut() throws SQLException {
        // Far more incomplete answers than a 100 millisecond wait with 5 millisecond pauses can use up.
        schemaHolds(List.of(), List.of());
        createMeetsAnObjectAnotherInstanceMade();
        schemaStaysIncomplete(1000);
        Duration wait = Duration.ofMillis(100);
        SchemaInitializer initializer = new SchemaInitializer(dataSource, properties, wait, Duration.ofMillis(5));
        long started = System.nanoTime();

        assertThatThrownBy(initializer::initialize)
                .isInstanceOf(IllegalStateException.class)
                .hasMessageContaining("still incomplete")
                .hasMessageContaining("prune_audit_log");

        assertThat(Duration.ofNanos(System.nanoTime() - started)).isGreaterThanOrEqualTo(wait);
        assertThat(answers).as("looks taken").hasSizeLessThan(2 * 1000 - 2);
    }

    @Test
    void anInterruptedWaitStopsLookingAndIsRefused() throws SQLException {
        schemaHolds(List.of(), List.of());
        createMeetsAnObjectAnotherInstanceMade();
        schemaStaysIncomplete(1000);
        SchemaInitializer initializer =
                new SchemaInitializer(dataSource, properties, SchemaInitializer.RACE_WAIT, Duration.ofSeconds(1));
        Thread.currentThread().interrupt();

        try {
            assertThatThrownBy(initializer::initialize)
                    .isInstanceOf(IllegalStateException.class)
                    .hasMessageContaining("still incomplete");
            assertThat(answers).as("only one look taken").hasSize(2 * 999);
            assertThat(Thread.currentThread().isInterrupted()).isTrue();
        } finally {
            Thread.interrupted();
        }
    }

    @Test
    void anyOtherCreationFailureIsRaisedAsItIs() throws SQLException {
        schemaHolds(List.of(), List.of());
        SQLException denied = new SQLException("permission denied for schema public", "42501");
        when(statement.execute(anyString())).thenThrow(denied);

        assertThatThrownBy(() -> initializer().initialize()).isSameAs(denied);
    }

    @Test
    void theExistenceQueriesAreScopedToTheCurrentSchemaOfTheEngine() throws SQLException {
        properties.getDatabase().setProvider(Provider.MYSQL);
        schemaHolds(EVERYTHING.subList(0, 2), EVERYTHING.subList(2, 7));

        initializer().initialize();

        verify(statement).executeQuery(contains("information_schema.tables WHERE table_schema = DATABASE()"));
        verify(statement).executeQuery(contains("information_schema.routines WHERE routine_schema = DATABASE()"));
    }
}
