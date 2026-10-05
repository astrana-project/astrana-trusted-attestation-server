package org.astrana.trustedattestation.data;

import jakarta.annotation.PostConstruct;
import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.time.Duration;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.stream.Collectors;
import javax.sql.DataSource;
import org.astrana.trustedattestation.config.TrustedAttestationProperties;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Provider;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.core.io.ClassPathResource;
import org.springframework.stereotype.Component;

/**
 * Creates the schema on first run, so IT staff only have to deploy the application, create an empty
 * database, and set connection details.
 *
 * <p>The schema script executed is the repository's own {@code shared/schema/*.sql}, packaged verbatim, the
 * same script a database administrator would review, rather than a second definition maintained in
 * Hibernate or Flyway that could drift from it. {@code ddl-auto} is off precisely so this is the only thing
 * that creates schema.
 *
 * <p>The existence check covers every object the application itself uses, the two tables and the two
 * procedures it runs, member_self_revoke_relationship and prune_audit_log, not the first table alone. The
 * other three procedures belong to the administrator. A hardened deployment applies the schema as the
 * administrator and runs the server as a login holding only the application role, and information_schema
 * shows such a login only the objects it may use, so a check of all five would refuse a complete schema. A
 * schema with some of the four is refused with a message naming what is missing, because an application
 * that found one table and carried on would fail later, at the first member's revoke, with a message that
 * says nothing about why. Creating the schema still runs the whole script.
 */
@Component
public class SchemaInitializer {

    private static final Logger log = LoggerFactory.getLogger(SchemaInitializer.class);

    /** The tables {@code shared/schema/*.sql} creates. */
    static final List<String> TABLES = List.of("member_relationships", "audit_log");

    /**
     * The procedures {@code shared/schema/*.sql} creates that the application itself runs, the only ones a
     * login holding just the application role can see. The administrator's grant, revoke and extend
     * procedures are left out of the check for that reason.
     */
    static final List<String> APPLICATION_PROCEDURES = List.of("member_self_revoke_relationship", "prune_audit_log");

    /**
     * How each engine reports "that object already exists": PostgreSQL's duplicate_table,
     * duplicate_object, duplicate_function and duplicate_schema SQLSTATEs, MySQL's table, index, procedure
     * and database already exists, and SQL Server's object and index already exists.
     */
    private static final Set<String> ALREADY_EXISTS_SQL_STATES = Set.of("42P07", "42710", "42723", "42P06");

    private static final Set<Integer> ALREADY_EXISTS_ERROR_CODES = Set.of(1050, 1061, 1304, 1007, 2714, 1913);

    /** How long the loser of a creation race waits for the winner to finish before giving up. */
    static final Duration RACE_WAIT = Duration.ofSeconds(30);

    /** How long the loser of a creation race pauses between looks at the schema. */
    static final Duration RACE_RECHECK_INTERVAL = Duration.ofSeconds(1);

    private final DataSource dataSource;
    private final TrustedAttestationProperties properties;
    private final Duration raceWait;
    private final Duration raceRecheckInterval;

    @Autowired
    public SchemaInitializer(DataSource dataSource, TrustedAttestationProperties properties) {
        this(dataSource, properties, RACE_WAIT, RACE_RECHECK_INTERVAL);
    }

    SchemaInitializer(
            DataSource dataSource,
            TrustedAttestationProperties properties,
            Duration raceWait,
            Duration raceRecheckInterval) {
        this.dataSource = dataSource;
        this.properties = properties;
        this.raceWait = raceWait;
        this.raceRecheckInterval = raceRecheckInterval;
    }

    @PostConstruct
    public void initialize() throws SQLException {
        Provider provider = properties.getDatabase().getProvider();

        try (Connection connection = dataSource.getConnection()) {
            List<String> missing = missingObjects(connection, provider);
            if (missing.isEmpty()) {
                log.info("Schema already present; nothing to create.");
                return;
            }

            if (missing.size() < TABLES.size() + APPLICATION_PROCEDURES.size()) {
                throw new IllegalStateException("The schema is incomplete: " + String.join(", ", missing)
                        + " missing. Apply shared/schema/schema-" + provider.scriptSuffix()
                        + ".sql in full, or drop what is there and let the application create it.");
            }

            if (!properties.getDatabase().isCreateSchemaOnStartup()) {
                throw new IllegalStateException(
                        "The schema does not exist and trusted-attestation.database.create-schema-on-startup "
                                + "is false. Apply shared/schema/schema-" + provider.scriptSuffix() + ".sql to the "
                                + "database, or turn the setting back on.");
            }

            create(connection, provider);
        }
    }

    /**
     * Runs the script. Not wrapped in one transaction, because MySQL commits schema changes implicitly, so
     * a rollback would be a promise the engine cannot keep.
     *
     * <p>Two instances starting against the same empty database race here, and one loses: its CREATE meets
     * an object the other just made. The loser catches that and checks again, once every {@link
     * #RACE_RECHECK_INTERVAL}, until the schema is complete or {@link #RACE_WAIT} runs out, because the
     * winner may still be part-way through the script. It continues once the schema is complete. Any other
     * failure, or a schema still incomplete when the wait runs out, is the failure it looks like, and the
     * operator resolves it by dropping what is there and restarting, correct for a first-run bootstrap
     * against an empty database.
     */
    private void create(Connection connection, Provider provider) throws SQLException {
        List<String> statements = SqlScriptSplitter.split(readScript(provider), provider);
        log.info("Creating schema for {} ({} statements).", provider, statements.size());

        try (Statement statement = connection.createStatement()) {
            for (String sql : statements) {
                statement.execute(sql);
            }
        } catch (SQLException failure) {
            if (!alreadyExists(failure)) {
                throw failure;
            }

            List<String> stillMissing = missingOnceTheRaceSettles(connection, provider);
            if (!stillMissing.isEmpty()) {
                throw new IllegalStateException(
                        "Another instance was creating the schema at the same time, and it is still incomplete: "
                                + String.join(", ", stillMissing) + " missing.",
                        failure);
            }

            log.info("Another instance created the schema first; nothing left to create.");
            return;
        }

        log.info("Schema created.");
    }

    /**
     * What is still missing once the schema is complete or the race wait has run out, whichever comes
     * first. An interrupted wait stops looking and answers with what the last look found.
     */
    private List<String> missingOnceTheRaceSettles(Connection connection, Provider provider) throws SQLException {
        long deadline = System.nanoTime() + raceWait.toNanos();
        List<String> missing = missingObjects(connection, provider);
        while (!missing.isEmpty() && deadline - System.nanoTime() > 0 && paused(raceRecheckInterval)) {
            missing = missingObjects(connection, provider);
        }

        return missing;
    }

    /** Pauses for the interval, or answers false at once if the thread is interrupted. */
    private static boolean paused(Duration interval) {
        try {
            Thread.sleep(interval);
            return true;
        } catch (InterruptedException _) {
            Thread.currentThread().interrupt();
            return false;
        }
    }

    /**
     * Every table, and every procedure the application runs, that the current schema does not hold, in the
     * script's order. All three engines expose {@code information_schema.tables} and {@code
     * information_schema.routines}, but each names the current schema differently, and the filter matters.
     * MySQL's information_schema spans every database the connection can see, so an unfiltered probe would
     * find a table of the same name in someone else's database and conclude ours already exists.
     */
    static List<String> missingObjects(Connection connection, Provider provider) throws SQLException {
        Set<String> tables = names(
                connection,
                "SELECT table_name FROM information_schema.tables WHERE table_schema = "
                        + provider.currentSchemaExpression() + " AND table_name IN (" + quoted(TABLES) + ")");
        Set<String> procedures = names(
                connection,
                "SELECT routine_name FROM information_schema.routines WHERE routine_schema = "
                        + provider.currentSchemaExpression() + " AND routine_type = 'PROCEDURE' AND routine_name IN ("
                        + quoted(APPLICATION_PROCEDURES) + ")");

        List<String> missing = new ArrayList<>();
        TABLES.stream().filter(table -> !tables.contains(table)).forEach(missing::add);
        APPLICATION_PROCEDURES.stream()
                .filter(procedure -> !procedures.contains(procedure))
                .forEach(missing::add);

        return missing;
    }

    private static Set<String> names(Connection connection, String sql) throws SQLException {
        Set<String> names = new HashSet<>();

        try (Statement statement = connection.createStatement();
                ResultSet results = statement.executeQuery(sql)) {
            while (results.next()) {
                String name = results.getString(1);
                if (name != null) {
                    names.add(name.toLowerCase(Locale.ROOT));
                }
            }
        }

        return names;
    }

    /** The names as a SQL list of literals. They are this class's own constants, never input. */
    private static String quoted(List<String> names) {
        return names.stream().map(name -> "'" + name + "'").collect(Collectors.joining(", "));
    }

    private static boolean alreadyExists(SQLException failure) {
        for (SQLException cause = failure; cause != null; cause = cause.getNextException()) {
            if (ALREADY_EXISTS_SQL_STATES.contains(cause.getSQLState())
                    || ALREADY_EXISTS_ERROR_CODES.contains(cause.getErrorCode())
                    || (cause.getMessage() != null
                            && cause.getMessage().toLowerCase(Locale.ROOT).contains("already exists"))) {
                return true;
            }
        }

        return false;
    }

    private static String readScript(Provider provider) {
        String resource = "schema/schema-" + provider.scriptSuffix() + ".sql";

        try (InputStream stream = new ClassPathResource(resource).getInputStream()) {
            return new String(stream.readAllBytes(), StandardCharsets.UTF_8);
        } catch (IOException exception) {
            throw new IllegalStateException(
                    "Packaged schema resource '" + resource + "' could not be "
                            + "read. The build must include the schema directory; see pom.xml.",
                    exception);
        }
    }
}
