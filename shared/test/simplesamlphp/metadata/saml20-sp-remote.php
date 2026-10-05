<?php

// The three implementations, each as a trusted SP. Entity IDs and ACS URLs match what each SAML stack
// publishes (Sustainsys, Spring Security, OneLogin). AssertionConsumerService is a list of endpoint
// definitions (SSP 2.x requires the array form, not a bare URL), all HTTP-POST. validate.authnrequest is
// off because these SPs sign their AuthnRequests with their own dev keys and this IdP does not carry those
// certificates -- the security that matters here is the IdP signing the assertion, which is always on.

$acs = static fn (string $location): array => [
    [
        'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        'Location' => $location,
        'index' => 0,
    ],
];

$metadata['https://localhost:15443/Saml2'] = [
    'AssertionConsumerService' => $acs('https://localhost:15443/Saml2/Acs'),
    'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
    'validate.authnrequest' => false,
    'sign.response' => true,
    'sign.assertion' => true,
];

$metadata['https://localhost:16443/saml2/service-provider-metadata/keycloak'] = [
    'AssertionConsumerService' => $acs('https://localhost:16443/login/saml2/sso/keycloak'),
    'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
    'validate.authnrequest' => false,
    'sign.response' => true,
    'sign.assertion' => true,
];

$metadata['https://localhost:17443/auth/saml/metadata'] = [
    'AssertionConsumerService' => $acs('https://localhost:17443/auth/saml/acs'),
    'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
    'validate.authnrequest' => false,
    'sign.response' => true,
    'sign.assertion' => true,
];
