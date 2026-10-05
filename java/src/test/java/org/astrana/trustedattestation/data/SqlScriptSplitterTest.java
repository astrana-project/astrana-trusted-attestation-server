package org.astrana.trustedattestation.data;

import static org.assertj.core.api.Assertions.assertThat;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.util.List;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Provider;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.EnumSource;
import org.springframework.core.io.ClassPathResource;

/**
 * The splitter runs against the real packaged schema scripts, not hand-written fixtures. If the contract
 * repo's DDL changes shape -- a new procedure, a different delimiter -- these tests see it.
 */
class SqlScriptSplitterTest {

    private static List<String> split(Provider provider) throws IOException {
        String resource = "schema/schema-" + provider.scriptSuffix() + ".sql";

        try (InputStream stream = new ClassPathResource(resource).getInputStream()) {
            return SqlScriptSplitter.split(new String(stream.readAllBytes(), StandardCharsets.UTF_8), provider);
        }
    }

    @Test
    void keepsATrailingCommentThatHasNoNewlineAfterIt() {
        // Behaviour pinned deliberately. On an unterminated line comment the scanner runs the comment to
        // the end of the script and keeps the statement before it, rather than dropping that statement.
        // Either would execute, because a trailing comment is inert SQL, but which one happens should be
        // a decision rather than a side effect of how the loop exits.
        List<String> statements =
                SqlScriptSplitter.split("SELECT 1; SELECT 2 -- no newline after this", Provider.POSTGRESQL);

        assertThat(statements).hasSize(2);
        assertThat(statements.get(1)).contains("SELECT 2");
    }

    @ParameterizedTest
    @EnumSource(Provider.class)
    void producesBothTablesAndEveryProcedure(Provider provider) throws IOException {
        List<String> statements = split(provider);

        assertThat(statements)
                .anyMatch(s -> s.contains("CREATE TABLE member_relationships"))
                .anyMatch(s -> s.contains("CREATE TABLE audit_log"))
                .anyMatch(s -> s.contains("grant_member_relationship"))
                .anyMatch(s -> s.contains("revoke_member_relationship"))
                .anyMatch(s -> s.contains("extend_member_relationship"))
                .anyMatch(s -> s.contains("member_self_revoke_relationship"))
                .anyMatch(s -> s.contains("prune_audit_log"));
    }

    @ParameterizedTest
    @EnumSource(Provider.class)
    void emitsNoEmptyOrCommentOnlyStatements(Provider provider) throws IOException {
        for (String statement : split(provider)) {
            assertThat(statement).isNotBlank();

            boolean hasExecutableLine =
                    statement.lines().map(String::strip).anyMatch(line -> !line.isEmpty() && !line.startsWith("--"));

            assertThat(hasExecutableLine)
                    .withFailMessage("Comment-only statement would be sent to the server:%n%s", statement)
                    .isTrue();
        }
    }

    @Test
    void neverPassesClientOnlyDirectivesToTheDriver() throws IOException {
        // GO and DELIMITER are features of each engine's command-line client. A JDBC driver rejects them.
        assertThat(split(Provider.SQL_SERVER)).allSatisfy(s -> assertThat(s).doesNotContain("\nGO"));

        assertThat(split(Provider.MYSQL)).allSatisfy(s -> {
            assertThat(s).doesNotContain("DELIMITER");
            assertThat(s).doesNotContain("$$");
        });
    }

    @Test
    void keepsAPostgresProcedureBodyWhole() throws IOException {
        // The bodies are dollar-quoted and full of semicolons. Splitting naively on ';' would cut a
        // procedure into fragments, each of which fails on its own.
        List<String> procedures = split(Provider.POSTGRESQL).stream()
                .filter(s -> s.contains("PROCEDURE revoke_member_relationship("))
                .toList();

        assertThat(procedures).hasSize(1);
        assertThat(procedures.getFirst())
                .contains("UPDATE member_relationships")
                .contains("INSERT INTO audit_log")
                .contains("END;");
    }

    @Test
    void keepsAMysqlProcedureBodyWhole() throws IOException {
        List<String> procedures = split(Provider.MYSQL).stream()
                .filter(s -> s.contains("PROCEDURE extend_member_relationship("))
                .toList();

        assertThat(procedures).hasSize(1);
        assertThat(procedures.getFirst())
                .contains("UPDATE member_relationships")
                .contains("INSERT INTO audit_log");
    }

    @Test
    void postgresSemicolonsInsideStringLiteralsDoNotSplit() {
        List<String> statements = SqlScriptSplitter.split(
                "INSERT INTO t (a) VALUES ('one; two'); INSERT INTO t (a) VALUES ('three');", Provider.POSTGRESQL);

        assertThat(statements).hasSize(2);
        assertThat(statements.getFirst()).contains("one; two");
    }

    @Test
    void sqlServerGoSeparatorIsMatchedOnlyOnItsOwnLine() {
        // "GO" appears inside identifiers and words; only a line that is nothing but GO is a batch break.
        List<String> statements = SqlScriptSplitter.split("SELECT 'ONGOING';\nGO\nSELECT 2;\n", Provider.SQL_SERVER);

        assertThat(statements).hasSize(2);
        assertThat(statements.getFirst()).contains("ONGOING");
    }
}
