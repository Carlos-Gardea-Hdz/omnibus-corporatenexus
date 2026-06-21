-- Auto-run on first pgsql container init (docker-entrypoint-initdb.d).
-- Creates the dedicated CENTRAL testing DB so Pest Feature tests run against
-- PostgreSQL 18 — never SQLite. Tenant DBs are created dynamically by
-- stancl/tenancy at test time (DB-per-tenant), so they are NOT created here.
--
-- The container superuser ($POSTGRES_USER, here `corporatenexus`) already owns
-- the cluster and therefore has CREATEDB/DROPDB — required so tenant
-- provisioning can create/drop one PostgreSQL database per tenant.
CREATE DATABASE corporatenexus_testing;
GRANT ALL PRIVILEGES ON DATABASE corporatenexus_testing TO corporatenexus;
