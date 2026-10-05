# 12. The schema is applied from the shared SQL files, there are no migrations

Accepted on 2026-10-05.

## Context

With one hand-written schema per engine ([record 11](0011-support-postgresql-mysql-and-sql-server-one-schema-each.md)),
the question is how it reaches a database and how it changes.

## Decision

Each schema file is the only definition of the schema and is applied as written. By default the application applies it
to an empty database on first run, which in PHP means the first request. It first looks for the objects the application
itself uses, the two tables and the two procedures its role may call, because a restricted role cannot see the
procedures it may not call. Only when none of them exists does it run the file, one statement at a time. A database that
holds some of those objects and not others is a partial schema, and the application refuses to start, or in PHP to serve
a request, with a message naming what is missing, rather than apply the file over it. When two instances start against
the same empty database at once, the one that finds an object already created waits until the schema is complete, or
until a short wait runs out, and continues once it is complete. A hardened deployment turns creation off and has the
installer apply the same file along with the roles and grants, with the application running as its restricted role.
There is no migration framework and no second definition of the schema in any implementation's code.

## Consequences

- A second definition would be a second thing to drift, and the schema a reviewer reads is the one that runs.
- By default the application's login owns the schema, so it holds the rights the role boundary would deny it until the
  deployment is hardened.
- Any future schema change is made by hand in two steps, first adding the new form beside the old one, then removing the
  old one. The files cannot safely be run twice, so an installer first checks for every object of an existing schema.
- Running the statements as one batch was rejected because MySQL commits data definition statements implicitly, so a
  batch is not atomic anyway, and a failed batch cannot say which statement failed.
