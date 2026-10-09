package org.astrana.trustedattestation.config;

import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.time.Instant;
import java.util.Base64;
import java.util.zip.Inflater;
import java.util.zip.InflaterOutputStream;
import org.opensaml.core.config.ConfigurationService;
import org.opensaml.core.xml.config.XMLObjectProviderRegistry;
import org.opensaml.saml.saml2.core.LogoutRequest;
import org.springframework.security.saml2.core.OpenSamlInitializationService;
import org.springframework.security.saml2.core.Saml2Error;
import org.springframework.security.saml2.core.Saml2ErrorCodes;
import org.springframework.security.saml2.provider.service.authentication.logout.Saml2LogoutRequestValidator;
import org.springframework.security.saml2.provider.service.authentication.logout.Saml2LogoutRequestValidatorParameters;
import org.springframework.security.saml2.provider.service.authentication.logout.Saml2LogoutValidatorResult;
import org.springframework.security.saml2.provider.service.registration.Saml2MessageBinding;
import org.w3c.dom.Element;

/**
 * Spring Security's checks of a logout request the identity provider sends, and one more: a request whose
 * NotOnOrAfter time has passed is refused.
 *
 * <p>Spring checks the signature, the issuer, the destination and the NameID, but not NotOnOrAfter, so a signed
 * logout request captured once would end a session at any later time. The .NET and PHP implementations refuse
 * one whose NotOnOrAfter time has passed, with no allowance for clock difference, and so does this. The refusal
 * carries Spring's {@code invalid_request} error, which Spring answers as it answers a request naming another
 * member: a signed logout response with the Requester status, and the session stays.
 */
final class UnexpiredLogoutRequestValidator implements Saml2LogoutRequestValidator {

    static {
        OpenSamlInitializationService.initialize();
    }

    private final Saml2LogoutRequestValidator delegate;

    UnexpiredLogoutRequestValidator(Saml2LogoutRequestValidator delegate) {
        this.delegate = delegate;
    }

    @Override
    public Saml2LogoutValidatorResult validate(Saml2LogoutRequestValidatorParameters parameters) {
        Saml2LogoutValidatorResult result = delegate.validate(parameters);
        if (result.hasErrors()) {
            return result;
        }

        Instant notOnOrAfter = notOnOrAfter(
                parameters.getLogoutRequest().getSamlRequest(),
                parameters.getLogoutRequest().getBinding());
        if (notOnOrAfter != null && !Instant.now().isBefore(notOnOrAfter)) {
            return Saml2LogoutValidatorResult.withErrors(new Saml2Error(
                            Saml2ErrorCodes.INVALID_REQUEST, "the logout request expired at " + notOnOrAfter))
                    .build();
        }
        return result;
    }

    /** The request's NotOnOrAfter, or null when it has none. Spring has already parsed it, so it parses. */
    private static Instant notOnOrAfter(String samlRequest, Saml2MessageBinding binding) {
        try {
            byte[] decoded = Base64.getMimeDecoder().decode(samlRequest);
            byte[] xml = binding == Saml2MessageBinding.REDIRECT ? inflate(decoded) : decoded;
            XMLObjectProviderRegistry registry = ConfigurationService.get(XMLObjectProviderRegistry.class);
            Element element = registry.getParserPool()
                    .parse(new ByteArrayInputStream(xml))
                    .getDocumentElement();
            LogoutRequest request = (LogoutRequest)
                    registry.getUnmarshallerFactory().getUnmarshaller(element).unmarshall(element);
            return request.getNotOnOrAfter();
        } catch (Exception exception) {
            throw new IllegalStateException("A logout request Spring accepted could not be read again", exception);
        }
    }

    private static byte[] inflate(byte[] deflated) throws IOException {
        ByteArrayOutputStream inflated = new ByteArrayOutputStream();
        try (InflaterOutputStream inflater = new InflaterOutputStream(inflated, new Inflater(true))) {
            inflater.write(deflated);
        }
        return inflated.toByteArray();
    }
}
