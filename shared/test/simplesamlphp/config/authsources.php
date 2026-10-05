<?php

// The four test members. `uid` is the fixed UUID the conformance fixtures grant against (SUBJECTS in
// shared/test/conformance/check.py); saml20-idp-hosted.php maps it onto the persistent NameID, so the SAML subject
// matches the same value the OIDC IdPs put in `sub`. `name` is sent as an attribute for the name claim.
// Password is "password" for all four. dave holds no relationship (a signed-in member with nothing -> 403).
$config = [
    'admin' => [
        'core:AdminPassword',
    ],

    'example-userpass' => [
        'exampleauth:UserPass',
        'users' => [
            'alice:password' => [
                'uid' => ['11111111-1111-4111-8111-111111111111'],
                'name' => ['Alice'],
            ],
            'bob:password' => [
                'uid' => ['22222222-2222-4222-8222-222222222222'],
                'name' => ['Bob'],
            ],
            'carol:password' => [
                'uid' => ['33333333-3333-4333-8333-333333333333'],
                'name' => ['Carol'],
            ],
            'dave:password' => [
                'uid' => ['44444444-4444-4444-8444-444444444444'],
                'name' => ['Dave'],
            ],
        ],
    ],
];
