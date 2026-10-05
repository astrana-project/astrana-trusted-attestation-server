namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// The language switcher's return path, which a form field supplies and so anyone can.
///
/// Only a path on this server is followed. It must start with a slash and not with a second slash or a
/// backslash, because a browser reads <c>//host</c> and <c>/\host</c> as another host. No backslash
/// anywhere, because some browsers read one later in the path as a slash too. No control character,
/// because a redirect carrying one is not a valid header and the server would answer 500 instead of
/// redirecting. Anything else lands on the landing page. The same rule in all three implementations.
/// </summary>
public static class ReturnPath
{
    public const string Fallback = "/";

    public static string Sanitize(string? next)
    {
        if (string.IsNullOrEmpty(next)
            || next[0] != '/'
            || next.StartsWith("//", StringComparison.Ordinal)
            || next.StartsWith("/\\", StringComparison.Ordinal)
            || next.Contains('\\')
            || next.Any(c => c < 0x20 || c == 0x7F))
        {
            return Fallback;
        }

        return next;
    }
}
