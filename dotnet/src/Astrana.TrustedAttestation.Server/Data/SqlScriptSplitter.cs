using System.Text;
using Astrana.TrustedAttestation.Server.Configuration;

namespace Astrana.TrustedAttestation.Server.Data;

/// <summary>
/// Splits the canonical <c>schema/*.sql</c> scripts into statements a database driver will accept.
///
/// The scripts are written to be run by each engine's own command-line client, so they use directives no
/// driver understands: <c>GO</c> batch separators on SQL Server, and <c>DELIMITER</c> on MySQL. Rather
/// than keep a second, driver-friendly copy of the DDL -- which would be one more place for the schema to
/// drift from the contract -- the scripts are embedded verbatim and split here.
/// </summary>
internal static class SqlScriptSplitter
{
    public static IReadOnlyList<string> Split(string script, DatabaseProvider provider) => provider switch
    {
        DatabaseProvider.SqlServer => SplitOnGo(script),
        DatabaseProvider.MySql => SplitOnDelimiter(script),
        DatabaseProvider.PostgreSql => SplitOnSemicolon(script, dollarQuoting: true),
        _ => throw new ArgumentOutOfRangeException(nameof(provider), provider, "Unsupported database provider."),
    };

    /// <summary>SQL Server: batches are separated by a line containing only <c>GO</c>.</summary>
    private static List<string> SplitOnGo(string script)
    {
        var statements = new List<string>();
        var batch = new StringBuilder();

        foreach (var line in script.Split('\n'))
        {
            if (line.Trim().Equals("GO", StringComparison.OrdinalIgnoreCase))
            {
                AddIfMeaningful(statements, batch.ToString());
                batch.Clear();
                continue;
            }

            batch.Append(line).Append('\n');
        }

        AddIfMeaningful(statements, batch.ToString());
        return statements;
    }

    /// <summary>
    /// MySQL: <c>DELIMITER x</c> changes the statement terminator so a procedure body's own semicolons
    /// do not end the <c>CREATE PROCEDURE</c>. The directive is a client feature, never sent to the server.
    /// </summary>
    private static List<string> SplitOnDelimiter(string script)
    {
        var statements = new List<string>();
        var current = new StringBuilder();
        var delimiter = ";";

        foreach (var rawLine in script.Split('\n'))
        {
            var line = rawLine.TrimEnd('\r');

            if (line.TrimStart().StartsWith("DELIMITER", StringComparison.OrdinalIgnoreCase))
            {
                // A pending statement cannot straddle a delimiter change.
                AddIfMeaningful(statements, current.ToString());
                current.Clear();

                var value = line.Trim()["DELIMITER".Length..].Trim();
                if (value.Length > 0)
                {
                    delimiter = value;
                }

                continue;
            }

            current.Append(line).Append('\n');

            // Only look for the delimiter at the end of a line: that is where these scripts put it, and it
            // avoids having to tokenise procedure bodies for a terminator that is never mid-line here.
            var text = current.ToString();
            var trimmed = text.TrimEnd();
            if (trimmed.EndsWith(delimiter, StringComparison.Ordinal))
            {
                AddIfMeaningful(statements, trimmed[..^delimiter.Length]);
                current.Clear();
            }
        }

        AddIfMeaningful(statements, current.ToString());
        return statements;
    }

    /// <summary>
    /// PostgreSQL: split on semicolons that are not inside a string, a comment, or a dollar-quoted block.
    /// Dollar quoting matters -- procedure bodies are wrapped in <c>$$ ... $$</c> and are full of semicolons.
    /// </summary>
    private static List<string> SplitOnSemicolon(string script, bool dollarQuoting)
    {
        var statements = new List<string>();
        var current = new StringBuilder();
        var index = 0;

        while (index < script.Length)
        {
            var c = script[index];

            // Everything that can hold a semicolon without ending a statement is copied whole, so the
            // only semicolon this loop ever acts on is one in open SQL. A semicolon starts no span, so
            // this returns index for it and the first branch takes over.
            var spanEnd = EndOfProtectedSpan(script, index, dollarQuoting);

            if (c == ';')
            {
                AddIfMeaningful(statements, current.ToString());
                current.Clear();
                index++;
            }
            else if (spanEnd > index)
            {
                current.Append(script, index, spanEnd - index);
                index = spanEnd;
            }
            else
            {
                current.Append(c);
                index++;
            }
        }

        AddIfMeaningful(statements, current.ToString());
        return statements;
    }

    /// <summary>
    /// The end of a span that has to be copied verbatim -- a comment, a string literal, or a
    /// dollar-quoted block -- or <paramref name="index"/> itself when the character there begins none
    /// of them.
    ///
    /// <para>Each of these can contain a semicolon that does not end a statement, which is the whole
    /// reason this cannot simply split on the character. Procedure bodies are the case that matters:
    /// PostgreSQL wraps them in <c>$$ ... $$</c> and they are full of semicolons.</para>
    /// </summary>
    private static int EndOfProtectedSpan(string script, int index, bool dollarQuoting)
    {
        var c = script[index];

        if (c == '-' && Peek(script, index + 1) == '-')
        {
            return EndOfLineComment(script, index);
        }

        if (c == '/' && Peek(script, index + 1) == '*')
        {
            return EndOfBlockComment(script, index);
        }

        if (c == '\'')
        {
            return EndOfQuotedLiteral(script, index);
        }

        // Dollar-quoted block: $tag$ ... $tag$, where tag may be empty.
        if (!dollarQuoting || c != '$' || !TryReadDollarTag(script, index, out var tag))
        {
            return index;
        }

        var closing = script.IndexOf(tag, index + tag.Length, StringComparison.Ordinal);
        return closing < 0 ? script.Length : closing + tag.Length;
    }

    /// <summary>Runs to the end of the line, or to the end of the script when the last line is
    /// unterminated.</summary>
    private static int EndOfLineComment(string script, int index)
    {
        var newline = script.IndexOf('\n', index);
        return newline < 0 ? script.Length : newline + 1;
    }

    /// <summary>An unterminated block comment runs to the end, rather than reopening as executable
    /// SQL.</summary>
    private static int EndOfBlockComment(string script, int index)
    {
        var closing = script.IndexOf("*/", index + 2, StringComparison.Ordinal);
        return closing < 0 ? script.Length : closing + 2;
    }

    /// <summary>A single-quoted literal, in which a doubled quote is an escaped quote rather than the
    /// end.</summary>
    private static int EndOfQuotedLiteral(string script, int index)
    {
        var end = index + 1;

        while (end < script.Length)
        {
            if (script[end] == '\'')
            {
                if (Peek(script, end + 1) != '\'')
                {
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

    private static char Peek(string text, int index) => index < text.Length ? text[index] : '\0';

    private static bool TryReadDollarTag(string script, int start, out string tag)
    {
        tag = string.Empty;
        var end = start + 1;

        while (end < script.Length && (char.IsLetterOrDigit(script[end]) || script[end] == '_'))
        {
            end++;
        }

        if (end >= script.Length || script[end] != '$')
        {
            return false;
        }

        tag = script[start..(end + 1)];
        return true;
    }

    /// <summary>Drops statements that are only whitespace or comments -- nothing to execute.</summary>
    private static void AddIfMeaningful(List<string> statements, string statement)
    {
        var trimmed = statement.Trim();
        if (trimmed.Length == 0)
        {
            return;
        }

        var hasCode = trimmed
            .Split('\n')
            .Select(line => line.Trim())
            .Any(line => line.Length > 0 && !line.StartsWith("--", StringComparison.Ordinal));

        if (hasCode)
        {
            statements.Add(trimmed);
        }
    }
}
