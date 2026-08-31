-- Canonical PostgreSQL extension provisioning for a scratch test database.
--
-- This is what `splicewire:beam:dev:isolated-test-db` runs when a project has not
-- declared its own `config('beam.dev.init')`. It exists because the alternative —
-- creating a BARE database and letting the first migration explain the problem — is
-- the single most misleading result this tool can produce: `splicewire/tower`'s suite
-- read `Tests: 465 failed, 412 passed` on a bare database, every one of them
-- `type "vector" does not exist`, and that reads exactly like a real regression.
--
-- Extensions live once at the DATABASE level, not per schema: tenant schemas share the
-- database and inherit these from `public`. Tenant provisioning in this estate ASSERTS
-- they exist (see Splicewire\Tower\Provisioning\TenantProvisioning) rather than creating
-- them, so they must be here before anything migrates.
--
-- Idempotent, and safe to run against a database that already has them.
--
-- NOT included, deliberately: `pgcrypto`. The only thing this estate wants from it is
-- `gen_random_uuid()`, which has been in the PostgreSQL core since 13 — verified on a
-- bare database with zero extensions on PostgreSQL 17 (Laravel Herd), 2026-08-30.
-- Creating it would be surplus, and every extension in this file is one more thing a
-- server can fail to have.
--
-- Each statement is executed SEPARATELY by the provisioner when this file is the
-- default, so one unavailable extension is named rather than taking the file down.

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;
CREATE EXTENSION IF NOT EXISTS vector;
