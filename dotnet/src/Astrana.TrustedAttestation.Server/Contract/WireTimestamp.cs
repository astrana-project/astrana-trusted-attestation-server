using System.Globalization;

namespace Astrana.TrustedAttestation.Server.Contract;

/// <summary>
/// How an instant is written on the API and on the page: ISO 8601 in UTC with a trailing <c>Z</c>, the
/// fraction omitted when it is zero and otherwise trimmed of trailing zeros. The same string in all three
/// implementations, so a consumer and a member reading the page see one spelling of one moment.
///
/// Spelled out here rather than left to the serialiser, whose output depends on the <c>DateTimeKind</c> a
/// database driver happens to stamp, and to the view, which used a format that is neither ISO 8601 nor
/// what the API sends.
/// </summary>
public static class WireTimestamp
{
    public static string Format(DateTime value)
    {
        // Stored instants are UTC. A Local kind can only come from a clock read in the process, and is
        // converted; Unspecified is taken as UTC, which is what the database read it back as.
        var utc = value.Kind == DateTimeKind.Local ? value.ToUniversalTime() : value;

        var text = utc.ToString("yyyy-MM-dd'T'HH:mm:ss", CultureInfo.InvariantCulture);

        var fraction = utc.Ticks % TimeSpan.TicksPerSecond;
        if (fraction != 0)
        {
            text += "." + fraction.ToString("0000000", CultureInfo.InvariantCulture).TrimEnd('0');
        }

        return text + "Z";
    }

    public static string? Format(DateTime? value) => value is null ? null : Format(value.Value);
}
