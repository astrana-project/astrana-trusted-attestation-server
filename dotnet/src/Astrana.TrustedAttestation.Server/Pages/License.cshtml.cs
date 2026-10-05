using Astrana.TrustedAttestation.Server.Contract;
using Microsoft.AspNetCore.Mvc.RazorPages;

namespace Astrana.TrustedAttestation.Server.Pages;

/// <summary>
/// The software's own licence, at a fixed public path the footer links to (see <see cref="Contract.Attribution"/>).
///
/// Anonymous, like the landing page, because a licence is public. Its content is the same attribution the
/// whole application already carries, the project name and the licence notice from the contract's
/// <c>attribution.json</c>, so there is one source of truth and nothing here to keep in step by hand.
/// </summary>
public sealed class LicenseModel(Attribution attribution) : PageModel
{
    public Attribution Attribution => attribution;
}
