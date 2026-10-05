<?php

// The hosted IdP. Entity ID is derived from baseurlpath:
//   http://localhost:9005/simplesaml/saml2/idp/metadata.php
// which is what the three implementations point their SAML metadata URL at.
$metadata['http://localhost:9005/saml2/idp/metadata.php'] = [
    'host' => '__DEFAULT__',
    'privatekey' => 'idp.key',
    'certificate' => 'idp.crt',
    'auth' => 'example-userpass',

    // Persistent NameID, set to the member's uid attribute (the fixed fixture UUID) rather than a
    // generated pseudonym -- so the SAML subject is the same value the OIDC IdPs put in `sub`, which is
    // what the conformance fixtures were granted against.
    'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
    'authproc' => [
        100 => [
            'class' => 'saml:AttributeNameID',
            'identifyingAttribute' => 'uid',
            'Format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        ],
    ],
];
