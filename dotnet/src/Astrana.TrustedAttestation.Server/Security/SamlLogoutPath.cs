namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// Which request paths are the single logout address, matched the way the SAML handler matches them, so
/// middleware that runs ahead of the handler acts on exactly the requests the handler takes as a logout. The
/// handler compares its module path as written and the command in any case, after skipping any slashes
/// between the two. A path with anything after the command, a trailing slash included, is not the single
/// logout address, and the handler answers it as not found.
/// </summary>
public static class SamlLogoutPath
{
    private const string Command = "Logout";

    public static bool Matches(PathString path, string modulePath) =>
        path.StartsWithSegments(modulePath, StringComparison.Ordinal, out var command)
        && string.Equals(command.Value?.TrimStart('/'), Command, StringComparison.OrdinalIgnoreCase);
}
