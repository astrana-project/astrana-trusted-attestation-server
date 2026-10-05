# What the two directories mean here

`Unit` and `Feature` are Laravel's names, and in this project they do **not** mean what those words usually mean.
Nothing in either directory touches a database or a network. Some tests in `Feature` send requests to routes, but
through Laravel's HTTP kernel inside the test process, not over a network. Every test here is a unit test by any
ordinary definition.

The split is one thing only: whether the test needs Laravel's application container booted.

|                 | Extends                      | Container |
| --------------- | ---------------------------- | --------- |
| `tests/Unit`    | `PHPUnit\Framework\TestCase` | No        |
| `tests/Feature` | `Tests\TestCase`             | Yes       |

A test lands in `Feature` when the code under test reaches for something the framework only provides once the
application exists, such as `config()`, a facade such as `DB`, `Http`, `Cache` or `Log`, Eloquent's attribute casts, or
the router. `MemberRelationship`, for instance, cannot even be constructed outside the container, because Eloquent
resolves its date casts through it. When you add a test to `Feature`, say in its docblock which of those it needs.

## Where the end-to-end testing is

Under `shared/test`, run against all three implementations:

- **`shared/test/conformance/check.py`** checks a running instance with a real database behind it, driving a real
  browser-shaped sign-in. It is implementation-blind, so the same run must pass against the .NET, Java and PHP
  instances, on PostgreSQL, MySQL and SQL Server.
- **`shared/test/schema-checks.py`** and **`shared/test/role-checks.py`** exercise the data model and the privilege
  separation directly against each engine.

Behaviour every implementation must share belongs in a suite all three run, not in three separate suites that can drift
apart while all staying green. Most of what this application does can only be shown against a real database in any case.

## What that means for coverage

Unit coverage is measured from `phpunit` alone and held to the project's target of 80% or higher. The conformance suite
also tests the controllers and services, but in a separate process that no coverage tool watches, so it adds nothing to
that figure.

`shared/test/mutation-harness.py` is the better measure of whether the suites together would notice a fault. It plants
faults one at a time, from a file describing them, and reports which ones the suites catch.
