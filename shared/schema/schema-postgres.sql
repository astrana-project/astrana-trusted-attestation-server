-- Astrana Trusted Attestation schema for PostgreSQL.
-- The reasoning is in docs/adr, decision records 9 to 18 for the tables, the procedures, the roles and the audit log.
-- One table holds every relationship, and each relationship has its own key, so presenting
-- one relationship's key can never reveal any other relationship the member holds.

-- PostgreSQL procedures default to SECURITY INVOKER (the caller's own
-- privileges), unlike MySQL, which defaults the opposite way. The access-control
-- design in decision record 16 relies on the procedures doing what the calling roles cannot.
-- ata_organisation_role has no table grants at all, only EXECUTE on its three
-- procedures. ata_application_role has only the narrow table grants listed below,
-- with no UPDATE on revoked_at, so it reaches that column only through
-- member_self_revoke_relationship. Without SECURITY DEFINER the procedures would run
-- with those narrow privileges and fail, and the roles would need the wider table
-- grants this design avoids. Keep SECURITY DEFINER on every procedure.
--
-- SECURITY DEFINER brings one risk. The procedure runs with the owner's privileges but
-- resolves table names through the caller's search_path, and the caller's temporary
-- schema sits at the front of that path. A caller who creates a temporary table named
-- audit_log would have the procedure write its audit row there, into a table that
-- vanishes with the session, and the real log would never see it. Every procedure
-- therefore sets its own search_path to pg_catalog, then public, then pg_temp last, so
-- the caller's temporary tables can never shadow the real ones. public is where an
-- unqualified CREATE TABLE puts these tables under the default search path. If you put
-- the tables in another schema, change public to that schema's name in those five
-- SET search_path lines.
-- shared/test/role-checks.py creates such a temporary table as each role and checks
-- the real log still gained its row.

-- PostgreSQL compares text exactly, case and trailing spaces included, so a plain
-- comparison is enough for every procedure to match the subject identifier and the
-- relationship type exactly. The MySQL and SQL Server schemas need a further comparison
-- for the same result, because their collations ignore trailing spaces.

CREATE TABLE member_relationships (
    id                    BIGSERIAL PRIMARY KEY,
    iam_subject_id        TEXT NOT NULL,             -- not unique alone, because a member can have several rows
    public_key            BYTEA NULL UNIQUE,          -- raw Ed25519 key, unique per relationship, NULL until the member registers it (see grant_member_relationship). PostgreSQL allows multiple NULLs in a UNIQUE column natively.
    relationship_type     TEXT NOT NULL,              -- from the fixed vocabulary in relationship-types.json
    relationship_subtype  TEXT NULL,                  -- ungoverned free text, optional
    expires_at            TIMESTAMPTZ NULL,
    revoked_at             TIMESTAMPTZ NULL,
    UNIQUE (iam_subject_id, relationship_type)
    -- A member holds at most one row per relationship type, and the database enforces it.
    -- Without this constraint a second grant of the same type would create a duplicate
    -- row, and PUT, revoke and extend could not tell which row to change.
    -- To restore an expired or revoked relationship, call extend_member_relationship, not
    -- grant_member_relationship again, which fails on the duplicate.
);
CREATE INDEX idx_member_relationships_iam_subject_id ON member_relationships (iam_subject_id);
-- The UNIQUE constraint on public_key gives /attest the index it looks keys up by.

CREATE TABLE audit_log (
    id                 BIGSERIAL PRIMARY KEY,
    event_type         TEXT NOT NULL,   -- one of the event names listed below
    -- The procedures below write relationship_granted_by_org, relationship_revoked_by_org and
    -- relationship_extended_by_org. member_self_revoke_relationship writes relationship_self_revoked.
    -- The application itself writes key_registered, key_cleared and key_removed (see decision
    -- record 17). No procedure here writes them.
    iam_subject_id     TEXT NULL,
    relationship_type  TEXT NULL,       -- which relationship was affected, where applicable
    actor              TEXT NULL,       -- a caller reference passed by the organisation's tooling, null for self-service events
    occurred_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- prune_audit_log deletes by occurred_at. Without this index the prune scans the whole
-- table and, on an engine that locks what it scans, blocks every audit insert for as
-- long as it runs. With it the prune touches only the rows it removes.
CREATE INDEX idx_audit_log_occurred_at ON audit_log (occurred_at);
-- Append-only. The application's database role is granted INSERT on audit_log and
-- no UPDATE or DELETE. Configure the role separately from this schema script.

-- The application's database role is also limited on member_relationships. The
-- running application's role (serving the self-service /me/... requests) can change
-- public_key directly and delete a row. It can set revoked_at only through
-- member_self_revoke_relationship, and it cannot change relationship_type,
-- relationship_subtype or expires_at. The database enforces this, not only the API.
-- A separate role changes those columns, by calling the organisation's three
-- procedures below, and has no table access of its own. Example grants (adjust role
-- names to match your deployment):
--   -- Run these first. PostgreSQL lets every role execute every routine by default, so the
--   -- GRANTs below add nothing until these revokes run, and ata_application_role could call
--   -- grant_member_relationship itself. shared/test/role-checks.py checks for this.
--   REVOKE EXECUTE ON PROCEDURE grant_member_relationship FROM PUBLIC;
--   REVOKE EXECUTE ON PROCEDURE revoke_member_relationship FROM PUBLIC;
--   REVOKE EXECUTE ON PROCEDURE extend_member_relationship FROM PUBLIC;
--   REVOKE EXECUTE ON PROCEDURE member_self_revoke_relationship FROM PUBLIC;
--   REVOKE EXECUTE ON PROCEDURE prune_audit_log FROM PUBLIC;
--
--   GRANT SELECT, DELETE, UPDATE (public_key) ON member_relationships TO ata_application_role;
--   GRANT EXECUTE ON PROCEDURE member_self_revoke_relationship TO ata_application_role;
--   GRANT INSERT ON audit_log TO ata_application_role;
--   -- INSERT alone is not enough. id is a BIGSERIAL, and its default reads the sequence. Without
--   -- this the application cannot write an audit row at all, and because that insert shares the
--   -- transaction with the change it records, self-service key registration fails with it.
--   GRANT USAGE ON SEQUENCE audit_log_id_seq TO ata_application_role;
--   -- The application prunes its own audit trail (a task inside the application, see decision record 18),
--   -- calling this procedure as itself. It deletes only rows past the retention window, so this is
--   -- controlled retention, not the ad hoc DELETE the role is otherwise denied. Without it, pruning
--   -- fails once the application runs as this restricted role rather than the database owner.
--   GRANT EXECUTE ON PROCEDURE prune_audit_log TO ata_application_role;
--
--   GRANT EXECUTE ON PROCEDURE grant_member_relationship TO ata_organisation_role;
--   GRANT EXECUTE ON PROCEDURE revoke_member_relationship TO ata_organisation_role;
--   GRANT EXECUTE ON PROCEDURE extend_member_relationship TO ata_organisation_role;
-- ata_application_role has EXECUTE on member_self_revoke_relationship specifically, not
-- direct UPDATE on revoked_at, because a plain column GRANT cannot express "can be set
-- once from NULL to the current time, and never cleared". Only a procedure can enforce
-- that direction.
-- ata_organisation_role is NOT also granted direct UPDATE or INSERT on
-- member_relationships, only EXECUTE on the procedures, so every write to the
-- organisation-only columns goes through a procedure, never through ad hoc SQL that
-- might skip the audit_log insert.

-- Creates the relationship row an organisation grants to a member, before they have ever
-- signed in to register a key (see decision record 1). The self-service page shows only
-- relationships that already exist, and this procedure creates them.
-- The relationship type must be one of the vocabulary in shared/contract/relationship-types.json,
-- compared exactly. Any other type raises an error before anything is written, so it leaves
-- neither a relationship nor an audit entry. The list below must name exactly the values in that
-- file, and shared/test/check-vocabulary.py fails when it does not.
-- A subject identifier with leading or trailing spaces is refused the same way, before anything
-- is written. PostgreSQL would store it as given, but MySQL and SQL Server ignore a trailing
-- space in their unique constraint, so all three engines refuse it, to behave the same.
CREATE OR REPLACE PROCEDURE grant_member_relationship(
    p_iam_subject_id TEXT, p_relationship_type TEXT, p_relationship_subtype TEXT DEFAULT NULL, p_actor TEXT DEFAULT NULL
)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
BEGIN
    IF p_iam_subject_id <> trim(p_iam_subject_id) THEN
        RAISE EXCEPTION 'The subject identifier has leading or trailing spaces.';
    END IF;

    IF p_relationship_type IS NULL OR p_relationship_type NOT IN (
        'advisor', 'apprentice', 'business_owner', 'citizen', 'client', 'contractor', 'director',
        'donor', 'employee', 'graduate', 'intellectual_property_holder', 'licensed_professional',
        'member', 'official', 'organiser', 'partner', 'property_owner', 'religious_leader',
        'resident', 'shareholder', 'student', 'tenant', 'trustee', 'veteran', 'volunteer'
    ) THEN
        RAISE EXCEPTION 'The relationship type is not in the vocabulary.';
    END IF;

    INSERT INTO member_relationships (iam_subject_id, public_key, relationship_type, relationship_subtype)
    VALUES (p_iam_subject_id, NULL, p_relationship_type, p_relationship_subtype);

    INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
    VALUES ('relationship_granted_by_org', p_iam_subject_id, p_relationship_type, p_actor);
END;
$$;

-- Revokes one relationship on the organisation's behalf. When the member holds no
-- relationship of that type, it raises an error and writes no audit entry, so the trail
-- never records a revoke that changed nothing.
CREATE OR REPLACE PROCEDURE revoke_member_relationship(
    p_iam_subject_id TEXT, p_relationship_type TEXT, p_actor TEXT DEFAULT NULL
)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
DECLARE
    v_rows INT;
BEGIN
    UPDATE member_relationships
    SET revoked_at = now()
    WHERE iam_subject_id = p_iam_subject_id
      AND relationship_type = p_relationship_type;
    GET DIAGNOSTICS v_rows = ROW_COUNT;

    IF v_rows = 0 THEN
        RAISE EXCEPTION 'No relationship matches this subject identifier and relationship type.';
    END IF;

    INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
    VALUES ('relationship_revoked_by_org', p_iam_subject_id, p_relationship_type, p_actor);
END;
$$;

-- Sets a relationship's expiry and clears any revocation, which is how the organisation
-- extends or restores it. When the member holds no relationship of that type, it raises an
-- error and writes no audit entry. A relationship that already has this expiry and no
-- revocation still matches, and the call is recorded.
CREATE OR REPLACE PROCEDURE extend_member_relationship(
    p_iam_subject_id TEXT, p_relationship_type TEXT, p_new_expires_at TIMESTAMPTZ, p_actor TEXT DEFAULT NULL
)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
DECLARE
    v_rows INT;
BEGIN
    UPDATE member_relationships
    SET expires_at = p_new_expires_at,
        revoked_at = NULL
    WHERE iam_subject_id = p_iam_subject_id
      AND relationship_type = p_relationship_type;
    GET DIAGNOSTICS v_rows = ROW_COUNT;

    IF v_rows = 0 THEN
        RAISE EXCEPTION 'No relationship matches this subject identifier and relationship type.';
    END IF;

    INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
    VALUES ('relationship_extended_by_org', p_iam_subject_id, p_relationship_type, p_actor);
END;
$$;

-- The member's own revoke, one way only. It sets revoked_at to the current time unless
-- the relationship is already revoked, never clears an existing revocation and never
-- touches expires_at. The organisation vouches for the relationship, so only the organisation
-- can restore or extend it (extend_member_relationship, above), but a member can always
-- protect themselves by revoking it, without needing the organisation's permission
-- first. Unlike a hard delete, the row survives, so the organisation can restore it
-- later without a new grant. Calling it on a relationship that is already revoked, or one
-- the member does not hold, changes nothing, raises no error and writes no audit entry.
CREATE OR REPLACE PROCEDURE member_self_revoke_relationship(
    p_iam_subject_id TEXT, p_relationship_type TEXT
)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
DECLARE
    v_rows INT;
BEGIN
    UPDATE member_relationships
    SET revoked_at = now()
    WHERE iam_subject_id = p_iam_subject_id
      AND relationship_type = p_relationship_type
      AND revoked_at IS NULL;
    GET DIAGNOSTICS v_rows = ROW_COUNT;

    IF v_rows > 0 THEN
        INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
        VALUES ('relationship_self_revoked', p_iam_subject_id, p_relationship_type, NULL);
    END IF;
END;
$$;

CREATE OR REPLACE PROCEDURE prune_audit_log(p_retention_days INT DEFAULT 1825)  -- default 5 years
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public, pg_temp AS $$
BEGIN
    DELETE FROM audit_log
    WHERE occurred_at < now() - (p_retention_days || ' days')::INTERVAL;
END;
$$;

-- Scheduling does NOT use pg_cron by default, because the extension is not guaranteed
-- on managed or shared hosting (see decision record 18). The application calls
-- prune_audit_log itself on every engine. .NET and Java run it on a schedule inside the
-- application, and PHP runs it while handling a request. An organisation can call it
-- from pg_cron or an external scheduler instead if its environment supports that and it
-- prefers it.
