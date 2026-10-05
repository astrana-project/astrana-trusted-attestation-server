package org.astrana.trustedattestation.data;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;
import java.util.Locale;
import org.astrana.trustedattestation.config.TrustedAttestationProperties.Provider;

/**
 * Splits the canonical {@code schema/*.sql} scripts into statements a JDBC driver will accept.
 *
 * <p>The scripts are written to be run by each engine's own command-line client, so they use directives
 * no driver understands: {@code GO} batch separators on SQL Server, and {@code DELIMITER} on MySQL.
 * Rather than keep a second, driver-friendly copy of the DDL -- one more place for the schema to drift
 * from the contract -- the scripts are packaged verbatim and split here.
 */
final class SqlScriptSplitter {

    private SqlScriptSplitter() {}

    static List<String> split(String script, Provider provider) {
        return switch (provider) {
            case SQL_SERVER -> splitOnGo(script);
            case MYSQL -> splitOnDelimiter(script);
            case POSTGRESQL -> splitOnSemicolon(script);
        };
    }

    /** SQL Server: batches are separated by a line containing only {@code GO}. */
    private static List<String> splitOnGo(String script) {
        List<String> statements = new ArrayList<>();
        StringBuilder batch = new StringBuilder();

        for (String line : script.split("\n", -1)) {
            if (line.strip().equalsIgnoreCase("GO")) {
                addIfMeaningful(statements, batch.toString());
                batch.setLength(0);
                continue;
            }

            batch.append(line).append('\n');
        }

        addIfMeaningful(statements, batch.toString());
        return statements;
    }

    /**
     * MySQL: {@code DELIMITER x} changes the statement terminator so a procedure body's own semicolons do
     * not end the {@code CREATE PROCEDURE}. The directive is a client feature, never sent to the server.
     */
    private static List<String> splitOnDelimiter(String script) {
        List<String> statements = new ArrayList<>();
        StringBuilder current = new StringBuilder();
        String delimiter = ";";

        for (String rawLine : script.split("\n", -1)) {
            String line = rawLine.stripTrailing();

            if (line.stripLeading().toUpperCase(Locale.ROOT).startsWith("DELIMITER")) {
                // A pending statement cannot straddle a delimiter change.
                addIfMeaningful(statements, current.toString());
                current.setLength(0);

                String value = line.strip().substring("DELIMITER".length()).strip();
                if (!value.isEmpty()) {
                    delimiter = value;
                }

                continue;
            }

            current.append(line).append('\n');

            // Only looked for at the end of a line: that is where these scripts put it, and it avoids
            // tokenising procedure bodies for a terminator that is never mid-line here.
            String trimmed = current.toString().stripTrailing();
            if (trimmed.endsWith(delimiter)) {
                addIfMeaningful(statements, trimmed.substring(0, trimmed.length() - delimiter.length()));
                current.setLength(0);
            }
        }

        addIfMeaningful(statements, current.toString());
        return statements;
    }

    /**
     * PostgreSQL: split on semicolons that are not inside a string, a comment, or a dollar-quoted block.
     * Dollar quoting matters -- procedure bodies are wrapped in {@code $$ ... $$} and full of semicolons.
     */
    private static List<String> splitOnSemicolon(String script) {
        List<String> statements = new ArrayList<>();
        StringBuilder current = new StringBuilder();
        int index = 0;

        while (index < script.length()) {
            char c = script.charAt(index);

            // Everything that can hold a semicolon without ending a statement is copied whole, so the
            // only semicolon this loop ever acts on is one in open SQL. A semicolon starts no span, so
            // this returns index for it and the first branch takes over.
            int spanEnd = endOfProtectedSpan(script, index);

            if (c == ';') {
                addIfMeaningful(statements, current.toString());
                current.setLength(0);
                index++;
            } else if (spanEnd > index) {
                current.append(script, index, spanEnd);
                index = spanEnd;
            } else {
                current.append(c);
                index++;
            }
        }

        addIfMeaningful(statements, current.toString());
        return statements;
    }

    /**
     * The end of a span that has to be copied verbatim -- a comment, a string literal, or a dollar-quoted
     * block -- or {@code index} itself when the character there begins none of them.
     *
     * <p>Each of these can contain a semicolon that does not end a statement, which is the whole reason
     * this cannot simply split on the character. Procedure bodies are the case that matters: PostgreSQL
     * wraps them in {@code $$ ... $$} and they are full of semicolons.
     */
    private static int endOfProtectedSpan(String script, int index) {
        char c = script.charAt(index);

        if (c == '-' && peek(script, index + 1) == '-') {
            return endOfLineComment(script, index);
        }

        if (c == '/' && peek(script, index + 1) == '*') {
            return endOfBlockComment(script, index);
        }

        if (c == '\'') {
            return endOfQuotedLiteral(script, index);
        }

        // Dollar-quoted block: $tag$ ... $tag$, where tag may be empty.
        String tag = dollarTagAt(script, index);
        if (tag == null) {
            return index;
        }

        int closing = script.indexOf(tag, index + tag.length());
        return closing < 0 ? script.length() : closing + tag.length();
    }

    /** Runs to the end of the line, or to the end of the script when the last line is unterminated. */
    private static int endOfLineComment(String script, int index) {
        int newline = script.indexOf('\n', index);
        return newline < 0 ? script.length() : newline + 1;
    }

    /** An unterminated block comment runs to the end, rather than reopening as executable SQL. */
    private static int endOfBlockComment(String script, int index) {
        int closing = script.indexOf("*/", index + 2);
        return closing < 0 ? script.length() : closing + 2;
    }

    /** A single-quoted literal, in which a doubled quote is an escaped quote rather than the end. */
    private static int endOfQuotedLiteral(String script, int index) {
        int end = index + 1;

        while (end < script.length()) {
            if (script.charAt(end) == '\'') {
                if (peek(script, end + 1) != '\'') {
                    return end + 1;
                }

                // A doubled quote: step over the first here, and the shared advance below takes the
                // second, so the pair is consumed without either being read as the closing quote.
                end++;
            }

            end++;
        }

        // Unterminated. The caller copies to here rather than reopening the rest as executable SQL.
        return end;
    }

    private static char peek(String text, int index) {
        return index < text.length() ? text.charAt(index) : '\0';
    }

    private static String dollarTagAt(String script, int start) {
        if (script.charAt(start) != '$') {
            return null;
        }

        int end = start + 1;
        while (end < script.length() && (Character.isLetterOrDigit(script.charAt(end)) || script.charAt(end) == '_')) {
            end++;
        }

        if (end >= script.length() || script.charAt(end) != '$') {
            return null;
        }

        return script.substring(start, end + 1);
    }

    /** Drops statements that are only whitespace or comments -- nothing to execute. */
    private static void addIfMeaningful(List<String> statements, String statement) {
        String trimmed = statement.strip();
        if (trimmed.isEmpty()) {
            return;
        }

        boolean hasCode = Arrays.stream(trimmed.split("\n"))
                .map(String::strip)
                .anyMatch(line -> !line.isEmpty() && !line.startsWith("--"));

        if (hasCode) {
            statements.add(trimmed);
        }
    }
}
