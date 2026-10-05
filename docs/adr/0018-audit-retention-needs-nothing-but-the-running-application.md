# 18. Audit retention needs nothing but the running application

Accepted on 2026-10-05.

## Context

Old audit entries have to be removed after the retention period
([record 17](0017-the-audit-log-is-append-only-apart-from-age-based-retention.md)), and something has to run that
removal. The organisations least likely to set that up, small ones with no compliance function, are the ones for whom an
audit log that grows forever is the worst outcome.

## Decision

The application runs the retention procedure itself. .NET and Java run it on a schedule inside their own process. PHP,
which has no long-lived process, runs it opportunistically from an incoming request. The application's role may execute
the retention procedure
([record 16](0016-granting-revoking-and-extending-are-enforced-in-the-database-not-in-the-application.md)), and that is
the only way it can delete from the audit log.

## Consequences

- No deployment needs a database job, a cron entry or a second process for the server to work correctly, including
  shared hosting.
- A failed prune is logged and retried rather than reported loudly. Holding that one way to delete audit rows is the
  price the application's role pays for needing nothing else.
- .NET runs the prune at a configured time of day and Java on a configured cron schedule, daily at 02:00 server time by
  default in both, and neither at start-up. A missed run is made up by the next one, but a process that is never running
  at that time, such as an idle shared application pool, never prunes, and needs a different run time or the
  organisation's own job.
- PHP prunes only when a request picked at random arrives after the prune interval has passed, and only if its cache is
  working, so a site with no traffic does not prune either.
- A database-native scheduler was rejected as the default because availability varies by engine and hosting tier. SQL
  Server Express has no SQL Server Agent, shared MySQL hosts often disable the event scheduler, and PostgreSQL has none
  built in. An organisation-run database job remains possible, since the procedure is there to call.
