-- Astrana Trusted Attestation schema for MySQL.
-- The reasoning is in docs/adr, decision records 9 to 18 for the tables, the procedures, the roles and the audit log.
-- One table holds every relationship, and each relationship has its own key, so presenting
-- one relationship's key can never reveal any other relationship the member holds.
-- From MySQL 8.0.13, a default that is an expression needs parentheses, so it is
-- DEFAULT (UTC_TIMESTAMP(6)) rather than DEFAULT UTC_TIMESTAMP(6). Without them the
-- statement is a syntax error. CURRENT_TIMESTAMP is the only exception.

-- MySQL stored procedures default to SQL SECURITY DEFINER (the opposite default from
-- PostgreSQL), so the procedures can do what the calling accounts cannot, exactly as
-- decision record 16 relies on, with no extra syntax needed here. ata_organisation has no table
-- access at all, only EXECUTE on its three procedures. ata_application has only the
-- narrow table grants listed below, with no UPDATE on revoked_at, so it reaches that
-- column only through member_self_revoke_relationship.

-- MySQL defaults to autocommit=1, so each statement is its own transaction unless it is
-- wrapped in START TRANSACTION and COMMIT. Without that, a procedure's two writes (for
-- example the member_relationships update and the audit_log insert) commit separately.
-- That would break, without any error, the rule in decision record 17 that an audit entry
-- is written in the same transaction as the change it logs, as an unguarded SQL Server
-- procedure would. Every multi-statement procedure below is wrapped in START TRANSACTION
-- and COMMIT with an exit handler that rolls back and re-signals on error. Do not remove
-- the transaction.

-- MySQL's default collation, utf8mb4_0900_ai_ci, compares case-insensitively and
-- ignores accents, so without saying otherwise 'employee' and 'EMPLOYEE' would be one
-- relationship type and two subject identifiers differing only in case would be one
-- member. A subject identifier is an opaque token from the identity provider and a
-- relationship type is a fixed lower-case word from the vocabulary, so both compare
-- exactly, byte for byte, which utf8mb4_bin gives. The same two columns carry the same
-- collation on audit_log, so a lookup in the trail is exact too, and the SQL Server
-- schema pins the equivalent (Latin1_General_100_BIN2). PostgreSQL compares exactly by
-- default and needs nothing.
--
-- utf8mb4_bin still ignores trailing spaces when it compares, so 'subject' and 'subject '
-- would be the same member. Every procedure that looks a relationship up therefore also
-- compares the two lengths in bytes, which LENGTH counts with trailing spaces included, and
-- a match is then exact.
-- The plain comparison stays in front, so the unique key can still find the row. The
-- procedure parameters carry the same character set and collation as the columns, so both
-- sides are measured in the same encoding whatever the database default is.

CREATE TABLE member_relationships (
    id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
    iam_subject_id        VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,   -- not unique alone, because a member can have several rows
    public_key            VARBINARY(32) NULL,              -- raw Ed25519 key, unique per relationship, NULL until the member registers it (see grant_member_relationship). MySQL allows multiple NULLs in a UNIQUE column natively.
    relationship_type     VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,    -- from the fixed vocabulary in relationship-types.json
    relationship_subtype  VARCHAR(255) NULL,                -- ungoverned free text, optional
    expires_at            DATETIME(6) NULL,            -- a UTC instant. DATETIME carries no zone, so the value stored is UTC wall-clock
    revoked_at            DATETIME(6) NULL,            -- a UTC instant, as above
    UNIQUE KEY uq_public_key (public_key),
    UNIQUE KEY uq_subject_type (iam_subject_id, relationship_type),
    -- A member holds at most one row per relationship type, and the database enforces it.
    -- Without this constraint a second grant of the same type would create a duplicate
    -- row, and PUT, revoke and extend could not tell which row to change.
    -- To restore an expired or revoked relationship, call extend_member_relationship, not
    -- grant_member_relationship again, which fails on the duplicate.
    KEY idx_iam_subject_id (iam_subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type         VARCHAR(32) NOT NULL,   -- one of the event names listed below
    -- The procedures below write relationship_granted_by_org, relationship_revoked_by_org and
    -- relationship_extended_by_org. member_self_revoke_relationship writes relationship_self_revoked.
    -- The application itself writes key_registered, key_cleared and key_removed (see decision
    -- record 17). No procedure here writes them.
    iam_subject_id     VARCHAR(255) COLLATE utf8mb4_bin NULL,
    relationship_type  VARCHAR(64) COLLATE utf8mb4_bin NULL,   -- which relationship was affected, where applicable
    actor              VARCHAR(255) NULL,      -- a caller reference passed by the organisation's tooling, null for self-service events
    occurred_at        DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),  -- a UTC instant
    -- prune_audit_log deletes by occurred_at. Without this index the prune scans the whole
    -- table under next-key locks and blocks every audit insert for as long as it runs. With
    -- it the prune locks only the rows it removes.
    KEY idx_audit_log_occurred_at (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Append-only. The application's database user is granted INSERT on audit_log and
-- no UPDATE or DELETE. Configure the user and its grants separately from this schema script.

-- The application's database user is also limited on member_relationships. The
-- running application's user (serving the self-service /me/... requests) can change
-- public_key directly and delete a row. It can set revoked_at only through
-- member_self_revoke_relationship, and it cannot change relationship_type,
-- relationship_subtype or expires_at. The database enforces this, not only the API.
-- A separate user changes those columns, by calling the organisation's three
-- procedures below, and has no table access of its own. Example grants (adjust user
-- names to match your deployment):
--   GRANT SELECT, DELETE, UPDATE (public_key) ON member_relationships TO 'ata_application'@'%';
--   GRANT EXECUTE ON PROCEDURE member_self_revoke_relationship TO 'ata_application'@'%';
--   GRANT INSERT ON audit_log TO 'ata_application'@'%';
--   -- The application prunes its own audit trail (a task inside the application, see decision record 18),
--   -- calling this procedure as itself. It deletes only rows past the retention window, so this is
--   -- controlled retention, not the ad hoc DELETE the account is otherwise denied. Without it, pruning
--   -- fails once the application runs as this restricted account rather than the database owner.
--   GRANT EXECUTE ON PROCEDURE prune_audit_log TO 'ata_application'@'%';
--   GRANT EXECUTE ON PROCEDURE grant_member_relationship TO 'ata_organisation'@'%';
--   GRANT EXECUTE ON PROCEDURE revoke_member_relationship TO 'ata_organisation'@'%';
--   GRANT EXECUTE ON PROCEDURE extend_member_relationship TO 'ata_organisation'@'%';
-- ata_application has EXECUTE on member_self_revoke_relationship specifically, not
-- direct UPDATE on revoked_at, because a plain column GRANT cannot express "can be set
-- once from NULL to the current time, and never cleared". Only a procedure can enforce
-- that direction.
-- ata_organisation is NOT also granted direct UPDATE or INSERT on
-- member_relationships, only EXECUTE on the procedures, so every write to the
-- organisation-only columns goes through a procedure, never through ad hoc SQL that
-- might skip the audit_log insert.

DELIMITER $$

-- MySQL procedures cannot have default parameter values, unlike SQL Server (`= NULL`)
-- and PostgreSQL (`DEFAULT NULL`), so every caller passes every argument, NULL included.
-- prune_audit_log treats a NULL retention as the five-year default.

-- Creates the relationship row an organisation grants to a member, before they have ever
-- signed in to register a key (see decision record 1). The self-service page shows only
-- relationships that already exist, and this procedure creates them.
-- The relationship type must be one of the vocabulary in shared/contract/relationship-types.json,
-- compared exactly. Any other type raises an error before anything is written, so it leaves
-- neither a relationship nor an audit entry. No type in the vocabulary ends in a space, so a
-- type that does is refused outright, and the case-sensitive list comparison is then exact.
-- The list below must name exactly the values in that file, and
-- shared/test/check-vocabulary.py fails when it does not.
-- A subject identifier with leading or trailing spaces is refused the same way, before anything
-- is written. The unique key ignores trailing spaces, so "subject " granted first would block a
-- later grant of the same type to "subject". The lengths are compared in bytes, which a trailing
-- space changes even where the collation's own comparison does not see it.
CREATE PROCEDURE grant_member_relationship(
    IN p_iam_subject_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
    IN p_relationship_type VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
    IN p_relationship_subtype VARCHAR(255), IN p_actor VARCHAR(255)
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF LENGTH(p_iam_subject_id) <> LENGTH(TRIM(p_iam_subject_id)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The subject identifier has leading or trailing spaces.';
    END IF;

    IF p_relationship_type IS NULL
       OR LENGTH(p_relationship_type) <> LENGTH(RTRIM(p_relationship_type))
       OR p_relationship_type NOT IN (
        'advisor', 'apprentice', 'business_owner', 'citizen', 'client', 'contractor', 'director',
        'donor', 'employee', 'graduate', 'intellectual_property_holder', 'licensed_professional',
        'member', 'official', 'organiser', 'partner', 'property_owner', 'religious_leader',
        'resident', 'shareholder', 'student', 'tenant', 'trustee', 'veteran', 'volunteer'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The relationship type is not in the vocabulary.';
    END IF;

    START TRANSACTION;
        INSERT INTO member_relationships (iam_subject_id, public_key, relationship_type, relationship_subtype)
        VALUES (p_iam_subject_id, NULL, p_relationship_type, p_relationship_subtype);

        INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
        VALUES ('relationship_granted_by_org', p_iam_subject_id, p_relationship_type, p_actor);
    COMMIT;
END$$

-- Revokes one relationship on the organisation's behalf. When the member holds no
-- relationship of that type, it raises an error and writes no audit entry, so the trail
-- never records a revoke that changed nothing. The relationship is found and locked first,
-- because ROW_COUNT() after an UPDATE counts the rows it changed, not the rows it matched.
CREATE PROCEDURE revoke_member_relationship(
    IN p_iam_subject_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
    IN p_relationship_type VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
    IN p_actor VARCHAR(255)
)
BEGIN
    DECLARE v_id BIGINT DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;
        SELECT id INTO v_id
        FROM member_relationships
        WHERE iam_subject_id = p_iam_subject_id
          AND relationship_type = p_relationship_type
          AND LENGTH(iam_subject_id) = LENGTH(p_iam_subject_id)
          AND LENGTH(relationship_type) = LENGTH(p_relationship_type)
        FOR UPDATE;

        IF v_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'No relationship matches this subject identifier and relationship type.';
        END IF;

        UPDATE member_relationships
        SET revoked_at = UTC_TIMESTAMP(6)
        WHERE id = v_id;

        INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
        VALUES ('relationship_revoked_by_org', p_iam_subject_id, p_relationship_type, p_actor);
    COMMIT;
END$$

-- Sets a relationship's expiry and clears any revocation, which is how the organisation
-- extends or restores it. When the member holds no relationship of that type, it raises an
-- error and writes no audit entry. A relationship that already has this expiry and no
-- revocation still matches, and the call is recorded. So the relationship is found and
-- locked first, rather than judged by ROW_COUNT(), which would count it as unchanged.
CREATE PROCEDURE extend_member_relationship(
    IN p_iam_subject_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
    IN p_relationship_type VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
    IN p_new_expires_at DATETIME(6), IN p_actor VARCHAR(255)
)
BEGIN
    DECLARE v_id BIGINT DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;
        SELECT id INTO v_id
        FROM member_relationships
        WHERE iam_subject_id = p_iam_subject_id
          AND relationship_type = p_relationship_type
          AND LENGTH(iam_subject_id) = LENGTH(p_iam_subject_id)
          AND LENGTH(relationship_type) = LENGTH(p_relationship_type)
        FOR UPDATE;

        IF v_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'No relationship matches this subject identifier and relationship type.';
        END IF;

        UPDATE member_relationships
        SET expires_at = p_new_expires_at,
            revoked_at = NULL
        WHERE id = v_id;

        INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
        VALUES ('relationship_extended_by_org', p_iam_subject_id, p_relationship_type, p_actor);
    COMMIT;
END$$

-- The member's own revoke, one way only. It sets revoked_at to the current time unless
-- the relationship is already revoked, never clears an existing revocation and never
-- touches expires_at. The organisation vouches for the relationship, so only the organisation
-- can restore or extend it (extend_member_relationship, above), but a member can always
-- protect themselves by revoking it, without needing the organisation's permission
-- first. Unlike a hard delete, the row survives, so the organisation can restore it
-- later without a new grant. Calling it on a relationship that is already revoked, or one
-- the member does not hold, changes nothing, raises no error and writes no audit entry.
-- The audit insert below runs only when the update changed a row. Here ROW_COUNT() is the
-- right test, because a row that matches always changes, its revoked_at moving from NULL.
CREATE PROCEDURE member_self_revoke_relationship(
    IN p_iam_subject_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
    IN p_relationship_type VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;
        UPDATE member_relationships
        SET revoked_at = UTC_TIMESTAMP(6)
        WHERE iam_subject_id = p_iam_subject_id
          AND relationship_type = p_relationship_type
          AND LENGTH(iam_subject_id) = LENGTH(p_iam_subject_id)
          AND LENGTH(relationship_type) = LENGTH(p_relationship_type)
          AND revoked_at IS NULL;

        IF ROW_COUNT() > 0 THEN
            INSERT INTO audit_log (event_type, iam_subject_id, relationship_type, actor)
            VALUES ('relationship_self_revoked', p_iam_subject_id, p_relationship_type, NULL);
        END IF;
    COMMIT;
END$$

-- A single statement, already atomic under MySQL's default autocommit, so no
-- explicit transaction is needed.
CREATE PROCEDURE prune_audit_log(IN p_retention_days INT)
BEGIN
    DECLARE v_retention_days INT DEFAULT 1825;  -- default 5 years
    IF p_retention_days IS NOT NULL THEN
        SET v_retention_days = p_retention_days;
    END IF;
    DELETE FROM audit_log
    WHERE occurred_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL v_retention_days DAY);
END$$

DELIMITER ;

-- Scheduling does NOT use the MySQL Event Scheduler by default, because
-- event_scheduler is often restricted on shared hosts (see decision record 18). The
-- application calls prune_audit_log itself on every engine. .NET and Java run it on a
-- schedule inside the application, and PHP runs it while handling a request. An
-- organisation can use CREATE EVENT or an external cron instead if its environment
-- supports that and it prefers it.
