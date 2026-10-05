-- Demonstration relationships, applied once by the seed service after the application has created the
-- schema and only while the table is empty. Granting, revoking and extending go through the same
-- procedures an organisation's own systems call, so the audit log shows them. The keys are fixtures: a
-- member's Astrana instance would generate a real keypair, but the demonstration needs keyed
-- relationships to show every status. Together the members show the four statuses: waiting for a key,
-- active, revoked and expired, and dave shows the page a member with no relationships sees.
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;

-- alice: employee waiting for a key, client active, licensed professional revoked by the organisation.
EXEC grant_member_relationship '11111111-1111-4111-8111-111111111111', 'employee', NULL, 'demo seed';

EXEC grant_member_relationship '11111111-1111-4111-8111-111111111111', 'client', NULL, 'demo seed';
UPDATE member_relationships
   SET public_key = 0x0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20
 WHERE iam_subject_id = '11111111-1111-4111-8111-111111111111' AND relationship_type = 'client';
INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
VALUES ('key_registered', '11111111-1111-4111-8111-111111111111', 'client', NULL);

EXEC grant_member_relationship '11111111-1111-4111-8111-111111111111', 'licensed_professional', NULL, 'demo seed';
UPDATE member_relationships
   SET public_key = 0x2122232425262728292a2b2c2d2e2f303132333435363738393a3b3c3d3e3f40
 WHERE iam_subject_id = '11111111-1111-4111-8111-111111111111' AND relationship_type = 'licensed_professional';
INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
VALUES ('key_registered', '11111111-1111-4111-8111-111111111111', 'licensed_professional', NULL);
EXEC revoke_member_relationship '11111111-1111-4111-8111-111111111111', 'licensed_professional', 'demo seed';

-- bob: employee, active.
EXEC grant_member_relationship '22222222-2222-4222-8222-222222222222', 'employee', NULL, 'demo seed';
UPDATE member_relationships
   SET public_key = 0x4142434445464748494a4b4c4d4e4f505152535455565758595a5b5c5d5e5f60
 WHERE iam_subject_id = '22222222-2222-4222-8222-222222222222' AND relationship_type = 'employee';
INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
VALUES ('key_registered', '22222222-2222-4222-8222-222222222222', 'employee', NULL);

-- carol: client, expired on 1 January 2026.
EXEC grant_member_relationship '33333333-3333-4333-8333-333333333333', 'client', NULL, 'demo seed';
UPDATE member_relationships
   SET public_key = 0x6162636465666768696a6b6c6d6e6f707172737475767778797a7b7c7d7e7f80
 WHERE iam_subject_id = '33333333-3333-4333-8333-333333333333' AND relationship_type = 'client';
INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
VALUES ('key_registered', '33333333-3333-4333-8333-333333333333', 'client', NULL);
EXEC extend_member_relationship '33333333-3333-4333-8333-333333333333', 'client', '2026-01-01T00:00:00', 'demo seed';
