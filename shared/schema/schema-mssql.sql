-- Astrana Trusted Attestation schema for Microsoft SQL Server.
-- The reasoning is in docs/adr, decision records 9 to 18 for the tables, the procedures, the roles and the audit log.
-- One table holds every relationship, and each relationship has its own key, so presenting
-- one relationship's key can never reveal any other relationship the member holds.

-- This design relies on ownership chaining, so that the procedures can do what the
-- calling roles cannot (see decision record 16). ata_organisation_role has no table access at
-- all, only EXECUTE on its three procedures. ata_application_role has only the narrow
-- table grants listed below, with no UPDATE on revoked_at, so it reaches that column
-- only through member_self_revoke_relationship. SQL Server resolves table access
-- through the procedure's owner automatically when the procedure and the underlying
-- tables share the same schema owner, which is the default in a simple single-schema
-- deployment. With a more complex, cross-schema ownership setup, ownership chaining
-- may not apply, and the procedures would need explicit EXECUTE AS OWNER instead.
-- Check this holds for your deployment rather than assuming it always does.

-- QUOTED_IDENTIFIER ON is required to create and use the filtered unique index below
-- (WHERE public_key IS NOT NULL). Without it, creating the filtered index fails. SQL
-- Server records ANSI_NULLS and QUOTED_IDENTIFIER when it creates each table or
-- procedure, not when it is called, so the script sets both before every CREATE
-- statement below instead of once at the top.
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- The usual SQL Server default collation, SQL_Latin1_General_CP1_CI_AS, compares
-- case-insensitively, so without saying otherwise 'employee' and 'EMPLOYEE' would be
-- one relationship type and two subject identifiers differing only in case would be
-- one member. A subject identifier is an opaque token from the identity provider and a
-- relationship type is a fixed lower-case word from the vocabulary, so both compare
-- exactly, code point for code point, which Latin1_General_100_BIN2 gives. The same two
-- columns carry the same collation on audit_log, so a lookup in the trail is exact too,
-- and the MySQL schema pins the equivalent (utf8mb4_bin). PostgreSQL compares exactly
-- by default and needs nothing.
--
-- Latin1_General_100_BIN2 still ignores trailing spaces when it compares, as every SQL
-- Server collation does, so 'subject' and 'subject ' would be the same member. Every
-- procedure that looks a relationship up therefore also compares the two lengths with
-- DATALENGTH, which counts bytes with trailing spaces included (LEN does not), and a match
-- is then exact. The plain comparison stays in front, so the index behind the unique
-- constraint can still find the row.
CREATE TABLE member_relationships (
    id                    BIGINT IDENTITY(1,1) PRIMARY KEY,   -- clustered, sequential inserts
    iam_subject_id        NVARCHAR(255) COLLATE Latin1_General_100_BIN2 NOT NULL,   -- not unique alone, because a member can have several rows
    public_key            VARBINARY(32) NULL,                  -- raw Ed25519 key, unique per relationship, NULL until the member registers it (see grant_member_relationship)
    relationship_type     NVARCHAR(64)  COLLATE Latin1_General_100_BIN2 NOT NULL,   -- from the fixed vocabulary in relationship-types.json
    relationship_subtype  NVARCHAR(255) NULL,                  -- ungoverned free text, optional
    expires_at            DATETIME2 NULL,              -- a UTC instant. DATETIME2 carries no zone, so the value stored is UTC wall-clock
    revoked_at            DATETIME2 NULL,              -- a UTC instant, as above
    CONSTRAINT uq_member_relationships_subject_type UNIQUE (iam_subject_id, relationship_type)
    -- A member holds at most one row per relationship type, and the database enforces it.
    -- Without this constraint a second grant of the same type would create a duplicate
    -- row, and PUT, revoke and extend could not tell which row to change.
    -- To restore an expired or revoked relationship, call extend_member_relationship, not
    -- grant_member_relationship again, which fails on the duplicate.
);
-- A SQL Server unique constraint allows only one NULL. PostgreSQL and MySQL allow many.
-- Many members can hold a granted relationship with no key yet, so public_key uses a
-- filtered unique index, which leaves NULLs out of the uniqueness check. The
-- (iam_subject_id, relationship_type) constraint above needs no filter, because neither
-- column can be NULL.
CREATE UNIQUE INDEX uq_member_relationships_public_key ON member_relationships (public_key)
    WHERE public_key IS NOT NULL;
CREATE INDEX idx_member_relationships_iam_subject_id ON member_relationships (iam_subject_id);

CREATE TABLE audit_log (
    id                 BIGINT IDENTITY(1,1) PRIMARY KEY,
    event_type         NVARCHAR(32) NOT NULL,   -- one of the event names listed below
    -- The procedures below write relationship_granted_by_org, relationship_revoked_by_org and
    -- relationship_extended_by_org. member_self_revoke_relationship writes relationship_self_revoked.
    -- The application itself writes key_registered, key_cleared and key_removed (see decision
    -- record 17). No procedure here writes them.
    iam_subject_id     NVARCHAR(255) COLLATE Latin1_General_100_BIN2 NULL,
    relationship_type  NVARCHAR(64) COLLATE Latin1_General_100_BIN2 NULL,   -- which relationship was affected, where applicable
    actor              NVARCHAR(255) NULL,      -- a caller reference passed by the organisation's tooling, null for self-service events
    occurred_at        DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()  -- a UTC instant
);
-- prune_audit_log deletes by occurred_at. Without this index the prune scans the whole
-- table and, on an engine that locks what it scans, blocks every audit insert for as
-- long as it runs. With it the prune touches only the rows it removes.
CREATE INDEX idx_audit_log_occurred_at ON audit_log (occurred_at);
-- Append-only. The application's database role is granted INSERT on audit_log and
-- no UPDATE or DELETE. Configure the login and role separately from this schema script.

-- The application's database role is also limited on member_relationships. The
-- running application's role (serving the self-service /me/... requests) can change
-- public_key directly and delete a row. It can set revoked_at only through
-- member_self_revoke_relationship, and it cannot change relationship_type,
-- relationship_subtype or expires_at. The database enforces this, not only the API.
-- A separate role changes those columns, by calling the organisation's three
-- procedures below, and has no table access of its own. Example grants (adjust role
-- names to match your deployment):
--   GRANT SELECT, DELETE, UPDATE (public_key) ON member_relationships TO ata_application_role;
--   GRANT EXECUTE ON member_self_revoke_relationship TO ata_application_role;
--   GRANT INSERT ON audit_log TO ata_application_role;
--   -- The application prunes its own audit trail (a task inside the application, see decision record 18),
--   -- calling this procedure as itself. It deletes only rows past the retention window, so this is
--   -- controlled retention, not the ad hoc DELETE the role is otherwise denied. Without it, pruning
--   -- fails once the application runs as this restricted role rather than the database owner.
--   GRANT EXECUTE ON prune_audit_log TO ata_application_role;
--   GRANT EXECUTE ON grant_member_relationship TO ata_organisation_role;
--   GRANT EXECUTE ON revoke_member_relationship TO ata_organisation_role;
--   GRANT EXECUTE ON extend_member_relationship TO ata_organisation_role;
-- ata_application_role has EXECUTE on member_self_revoke_relationship specifically, not
-- direct UPDATE on revoked_at, because a plain column GRANT cannot express "can be set
-- once from NULL to the current time, and never cleared". Only a procedure can enforce
-- that direction.
-- ata_organisation_role is NOT also granted direct UPDATE or INSERT on
-- member_relationships, only EXECUTE on the procedures, so every write to the
-- organisation-only columns goes through a procedure, never through ad hoc SQL that
-- might skip the audit_log insert.

GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- Creates the relationship row an organisation grants to a member, before they have ever
-- signed in to register a key (see decision record 1). The self-service page shows only
-- relationships that already exist, and this procedure creates them.
-- Decision record 17 says the audit entry is written in the same transaction as the
-- change it logs. SET XACT_ABORT ON and an explicit transaction make that true. Without
-- them, an error partway through, such as the audit_log insert failing, would leave the
-- member_relationships write saved on its own, without any error about it.
-- The relationship type must be one of the vocabulary in shared/contract/relationship-types.json,
-- compared exactly. Any other type raises an error before anything is written, so it leaves
-- neither a relationship nor an audit entry. A parameter takes the database's default collation,
-- which is often case-insensitive, so the list comparison names the binary collation itself. No
-- type in the vocabulary ends in a space, so a type that does is refused outright, and that
-- comparison is then exact. The list below must name exactly the values in that file, and
-- shared/test/check-vocabulary.py fails when it does not.
-- A subject identifier with leading or trailing spaces is refused the same way, before anything
-- is written. The unique constraint ignores trailing spaces, so "subject " granted first would block a
-- later grant of the same type to "subject". DATALENGTH counts bytes, trailing spaces included,
-- which LEN does not.
CREATE OR ALTER PROCEDURE grant_member_relationship
    @iam_subject_id NVARCHAR(255),
    @relationship_type NVARCHAR(64),
    @relationship_subtype NVARCHAR(255) = NULL,
    @actor NVARCHAR(255) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF DATALENGTH(@iam_subject_id) <> DATALENGTH(LTRIM(RTRIM(@iam_subject_id)))
        THROW 50000, N'The subject identifier has leading or trailing spaces.', 1;

    IF @relationship_type IS NULL
       OR DATALENGTH(@relationship_type) <> DATALENGTH(RTRIM(@relationship_type))
       OR @relationship_type COLLATE Latin1_General_100_BIN2 NOT IN (
        N'advisor', N'apprentice', N'business_owner', N'citizen', N'client', N'contractor', N'director',
        N'donor', N'employee', N'graduate', N'intellectual_property_holder', N'licensed_professional',
        N'member', N'official', N'organiser', N'partner', N'property_owner', N'religious_leader',
        N'resident', N'shareholder', N'student', N'tenant', N'trustee', N'veteran', N'volunteer'
    )
        THROW 50000, N'The relationship type is not in the vocabulary.', 1;

    BEGIN TRANSACTION;
        INSERT INTO member_relationships (iam_subject_id, public_key, relationship_type, relationship_subtype)
        VALUES (@iam_subject_id, NULL, @relationship_type, @relationship_subtype);

        INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
        VALUES ('relationship_granted_by_org', @iam_subject_id, @relationship_type, @actor);
    COMMIT TRANSACTION;
END
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- Revokes one relationship on the organisation's behalf. When the member holds no
-- relationship of that type, it raises an error and writes no audit entry, so the trail
-- never records a revoke that changed nothing. With XACT_ABORT ON, THROW rolls the
-- transaction back as it leaves.
CREATE OR ALTER PROCEDURE revoke_member_relationship
    @iam_subject_id NVARCHAR(255),
    @relationship_type NVARCHAR(64),
    @actor NVARCHAR(255) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;
        UPDATE member_relationships
        SET revoked_at = SYSUTCDATETIME()
        WHERE iam_subject_id = @iam_subject_id
          AND relationship_type = @relationship_type
          AND DATALENGTH(iam_subject_id) = DATALENGTH(@iam_subject_id)
          AND DATALENGTH(relationship_type) = DATALENGTH(@relationship_type);

        IF @@ROWCOUNT = 0
            THROW 50000, N'No relationship matches this subject identifier and relationship type.', 1;

        INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
        VALUES ('relationship_revoked_by_org', @iam_subject_id, @relationship_type, @actor);
    COMMIT TRANSACTION;
END
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- Sets a relationship's expiry and clears any revocation, which is how the organisation
-- extends or restores it. When the member holds no relationship of that type, it raises an
-- error and writes no audit entry. A relationship that already has this expiry and no
-- revocation still matches, and the call is recorded, because @@ROWCOUNT counts the rows an
-- UPDATE matched, changed or not.
CREATE OR ALTER PROCEDURE extend_member_relationship
    @iam_subject_id NVARCHAR(255),
    @relationship_type NVARCHAR(64),
    @new_expires_at DATETIME2,
    @actor NVARCHAR(255) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;
        UPDATE member_relationships
        SET expires_at = @new_expires_at,
            revoked_at = NULL
        WHERE iam_subject_id = @iam_subject_id
          AND relationship_type = @relationship_type
          AND DATALENGTH(iam_subject_id) = DATALENGTH(@iam_subject_id)
          AND DATALENGTH(relationship_type) = DATALENGTH(@relationship_type);

        IF @@ROWCOUNT = 0
            THROW 50000, N'No relationship matches this subject identifier and relationship type.', 1;

        INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
        VALUES ('relationship_extended_by_org', @iam_subject_id, @relationship_type, @actor);
    COMMIT TRANSACTION;
END
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- The member's own revoke, one way only. It sets revoked_at to the current time unless
-- the relationship is already revoked, never clears an existing revocation and never
-- touches expires_at. The organisation vouches for the relationship, so only the organisation
-- can restore or extend it (extend_member_relationship, above), but a member can always
-- protect themselves by revoking it, without needing the organisation's permission
-- first. Unlike a hard delete, the row survives, so the organisation can restore it
-- later without a new grant. Calling it on a relationship that is already revoked, or one
-- the member does not hold, changes nothing, raises no error and writes no audit entry.
-- The audit insert below runs only when the update changed a row.
CREATE OR ALTER PROCEDURE member_self_revoke_relationship
    @iam_subject_id NVARCHAR(255),
    @relationship_type NVARCHAR(64)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    BEGIN TRANSACTION;
        UPDATE member_relationships
        SET revoked_at = SYSUTCDATETIME()
        WHERE iam_subject_id = @iam_subject_id
          AND relationship_type = @relationship_type
          AND DATALENGTH(iam_subject_id) = DATALENGTH(@iam_subject_id)
          AND DATALENGTH(relationship_type) = DATALENGTH(@relationship_type)
          AND revoked_at IS NULL;

        IF @@ROWCOUNT > 0
        BEGIN
            INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
            VALUES ('relationship_self_revoked', @iam_subject_id, @relationship_type, NULL);
        END
    COMMIT TRANSACTION;
END
GO

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE prune_audit_log
    @retention_days INT = 1825  -- default 5 years, see decision record 18
AS
BEGIN
    SET NOCOUNT ON;
    DELETE FROM audit_log
    WHERE occurred_at < DATEADD(DAY, -@retention_days, SYSUTCDATETIME());
END
GO

-- Scheduling does NOT use SQL Server Agent, because Express edition does not have it
-- (see decision record 18). The application calls prune_audit_log itself on every engine.
-- .NET and Java run it on a schedule inside the application, and PHP runs it while handling
-- a request. An organisation can call it from an external scheduler instead if it prefers.
