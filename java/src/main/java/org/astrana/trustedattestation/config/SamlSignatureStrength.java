package org.astrana.trustedattestation.config;

import java.io.IOException;
import java.io.StringReader;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.regex.Pattern;
import javax.xml.XMLConstants;
import javax.xml.parsers.DocumentBuilderFactory;
import javax.xml.parsers.ParserConfigurationException;
import javax.xml.xpath.XPath;
import javax.xml.xpath.XPathConstants;
import javax.xml.xpath.XPathFactory;
import org.w3c.dom.Document;
import org.w3c.dom.NodeList;
import org.xml.sax.InputSource;
import org.xml.sax.SAXException;

/**
 * Finds the signature and digest algorithms in a received SAML response that are weaker than SHA-256.
 *
 * <p>OpenSAML validates that a signature is cryptographically sound but not that it was made with a modern
 * algorithm, so on its own Spring Security would accept an assertion signed with SHA-1 -- long broken for
 * collision resistance. The .NET implementation (Sustainsys) refuses any incoming signature or digest weaker
 * than SHA-256 by default, and the whole point is that the same assertion is accepted or refused whichever
 * stack receives it; this closes the gap so Java (and PHP) refuse it too. WSO2, for one, signs with a SHA-1
 * digest under some configurations.
 *
 * <p>Both halves of an XML signature are checked -- the {@code SignatureMethod} (how the SignedInfo is
 * signed) and every Reference's {@code DigestMethod} (how the signed content is hashed) -- because a
 * signature is only as strong as its weakest half: a SHA-256 signature over a SHA-1 digest is still forgeable
 * through a digest collision, which is exactly the shape WSO2 emits (RSA-SHA256 signature, SHA-1 digest).
 */
final class SamlSignatureStrength {

    private SamlSignatureStrength() {}

    /** An algorithm URI is strong when it names SHA-256/384/512; SHA-1 (…#sha1, …#rsa-sha1) and MD5 are not. */
    private static final Pattern STRONG = Pattern.compile("sha(256|384|512)$", Pattern.CASE_INSENSITIVE);

    private static final String ALGORITHM_XPATH =
            "//*[local-name()='SignatureMethod' or local-name()='DigestMethod']/@Algorithm";

    /**
     * The distinct weak algorithm URIs in the given SAML response XML, in document order; empty when every
     * signature and digest is SHA-256 or better.
     *
     * <p>A response that will not parse carries no verifiable signature -- the signature check has already
     * failed it -- so nothing extra is reported here rather than second-guessing the parser.
     */
    static List<String> weakAlgorithms(String responseXml) {
        if (responseXml == null || responseXml.isBlank()) {
            return List.of();
        }

        try {
            Document document = parse(responseXml);
            XPath xpath = XPathFactory.newInstance().newXPath();
            NodeList algorithms = (NodeList) xpath.evaluate(ALGORITHM_XPATH, document, XPathConstants.NODESET);

            LinkedHashSet<String> weak = new LinkedHashSet<>();
            for (int i = 0; i < algorithms.getLength(); i++) {
                String algorithm = algorithms.item(i).getNodeValue();
                if (algorithm != null && !STRONG.matcher(algorithm).find()) {
                    weak.add(algorithm);
                }
            }
            return List.copyOf(weak);
        } catch (Exception _) {
            return List.of();
        }
    }

    private static Document parse(String xml) throws ParserConfigurationException, SAXException, IOException {
        DocumentBuilderFactory factory = DocumentBuilderFactory.newInstance();
        factory.setNamespaceAware(true);
        // This parses attacker-supplied XML, so shut off the external-entity and DOCTYPE processing that
        // would otherwise make it an XXE sink; none of it is needed to read signature algorithm URIs.
        factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        factory.setFeature("http://xml.org/sax/features/external-general-entities", false);
        factory.setFeature("http://xml.org/sax/features/external-parameter-entities", false);
        factory.setAttribute(XMLConstants.ACCESS_EXTERNAL_DTD, "");
        factory.setAttribute(XMLConstants.ACCESS_EXTERNAL_SCHEMA, "");
        factory.setXIncludeAware(false);
        factory.setExpandEntityReferences(false);
        return factory.newDocumentBuilder().parse(new InputSource(new StringReader(xml)));
    }
}
