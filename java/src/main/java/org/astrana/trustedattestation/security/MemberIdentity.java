package org.astrana.trustedattestation.security;

/**
 * A member as the organisation's identity and access management system describes them, for the duration of
 * one request.
 *
 * <p>Only {@code iamSubjectId} is ever stored, and only as the key the relationship rows hang from.
 * {@code name} exists solely so the self-service page can show the member who they are signed in as, and
 * is never written anywhere.
 *
 * <p>It carries no relationship type or subtype. A relationship exists only because the organisation
 * created it, and the database is the only place that records one.
 *
 * @param iamSubjectId the organisation's own subject identifier for the member, which never leaves the
 *     server
 * @param name display name from the session, never stored
 */
public record MemberIdentity(String iamSubjectId, String name) {}
