# Foundation suite — setup and run

Covers the schema, the seeders, the Parts Catalog, part identity / repeat-buy detection, warranties
and the fault-location axis. Runs against its own MySQL schema, **`fleet_test`**.

## Why the setup is manual

`FoundationTestCase` uses `DatabaseTransactions`, not `RefreshDatabase`, so **no test issues DDL**:
each test runs inside a transaction that is rolled back. That is deliberate — `migrate:fresh` is
broken on this MySQL install and poisons any schema it touches (see the memory note
"migrations cannot run from empty"). The price is that the schema and a small reference baseline have
to exist in `fleet_test` **before** the suite runs, which is what the commands below do.

Build the schema by **cloning the live one**, never by migrating from empty — the migration history
cannot replay from scratch on this install.

## One-time setup

Run from `backend/`. Adjust the MySQL path if XAMPP lives elsewhere.

```sh
MYSQL=/c/xampp/mysql/bin
"$MYSQL/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS fleet_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Structure only — no rows. The suite builds its own fixtures.
"$MYSQL/mysqldump.exe" -u root --no-data --routines laravel > /tmp/fleet_test_schema.sql
"$MYSQL/mysql.exe" -u root fleet_test < /tmp/fleet_test_schema.sql
```

Then the reference data the tests assert against. **Order matters**: `VehicleLocationSeeder` stamps
`location_mode` onto rows of `fault_catalog` / `damage_catalog`, so those must exist first — run it
last, and re-run it if you ever re-seed the fault catalog.

```sh
DB_DATABASE=fleet_test php artisan db:seed --class=RolesAndPermissionsSeeder --force
DB_DATABASE=fleet_test php artisan db:seed --class=ComponentCatalogSeeder    --force
DB_DATABASE=fleet_test php artisan db:seed --class=FaultCatalogSeeder        --force
DB_DATABASE=fleet_test php artisan db:seed --class=VehicleLocationSeeder     --force
```

## Run

```sh
php vendor/bin/phpunit -c phpunit.foundation.xml
```

Expect a fully green suite. `phpunit.foundation.xml` supplies the `fleet_test` connection itself, so
no environment variable is needed at run time.

## After a schema change

A new migration does **not** reach `fleet_test` automatically. Apply it explicitly:

```sh
DB_DATABASE=fleet_test php artisan migrate --force
```

If a migration's own backfill depends on reference data (the parts-identity backfill reads
`component_catalog`), seed that reference data first or the backfill runs against an empty vocabulary
and links nothing — the tests then fail for a reason that has nothing to do with the code.
