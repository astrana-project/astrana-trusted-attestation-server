using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using Astrana.TrustedAttestation.Server.Configuration;

namespace Astrana.TrustedAttestation.Server.Security;

/// <summary>
/// This service provider's SAML signing keypair, read once while the application starts. A missing or
/// unreadable file stops start-up with a message naming the file, as the Java and PHP implementations do,
/// rather than letting the server start and then fail every request.
/// </summary>
public static class SamlSigningCertificate
{
    private const string PathSetting = "TrustedAttestation:Iam:Saml:SigningCertificatePath";
    private const string PasswordSetting = "TrustedAttestation:Iam:Saml:SigningCertificatePassword";

    /// <summary>
    /// The certificate the configured path names, or null when no path is configured. A relative path is
    /// resolved against the content root, so the same setting means the same file whether the app is started
    /// from its project directory or from a published output folder.
    /// </summary>
    public static X509Certificate2? Load(SamlOptions saml, string contentRootPath)
    {
        if (string.IsNullOrWhiteSpace(saml.SigningCertificatePath))
        {
            return null;
        }

        var certificatePath = Path.IsPathRooted(saml.SigningCertificatePath)
            ? saml.SigningCertificatePath
            : Path.Combine(contentRootPath, saml.SigningCertificatePath);

        if (!File.Exists(certificatePath))
        {
            throw new InvalidOperationException(
                $"The SAML signing certificate '{certificatePath}' does not exist. {PathSetting} is resolved " +
                "against the content root unless it is absolute.");
        }

        try
        {
            return X509CertificateLoader.LoadPkcs12FromFile(certificatePath, saml.SigningCertificatePassword);
        }
        catch (CryptographicException exception)
        {
            throw new InvalidOperationException(
                $"The SAML signing certificate '{certificatePath}' could not be read. It must be a PKCS#12 file, " +
                $"and {PasswordSetting} must be its password.",
                exception);
        }
    }
}
