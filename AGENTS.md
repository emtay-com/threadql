# Repository Guidelines

## Project Structure & Module Organization
- ThreadQL is a Laravel 12 + React 19 application for tenant-scoped Slack-to-SQL workflows, plus an MCP server for external tool use.
- Core backend code lives in `app/`. The main areas in active use are:
  - `app/Command` and `app/CommandHandlers`: command/handler pairs for query, schema, definition, Slack, and prompt flows. These are classmapped in `composer.json`.
  - `app/Http/Controllers`: Slack endpoints, admin API controllers, tenant utility endpoints, and the web panel controller.
  - `app/Jobs`: queued query execution, pagination, CSV export, notifications, schema crawling, and feedback delivery.
  - `app/Services`: LLM orchestration, SQL helpers, export services, and query persistence/status services.
  - `app/Infrastructure`: dynamic DB connectors, SSH tunnel support, Slack clients/builders, DSN parsing, and command bus plumbing.
  - `app/Mcp`: MCP server definition and tool implementations (`RunSqlQueryTool`, `ExportCsvTool`, `FetchTableDdlsTool`, `RunQueryForCsvExportTool`, `RequestDefinitionTool`).
  - `app/Prompt` and `resources/views/prompts`: prompt builders plus Blade prompt partials used for initial and follow-up LLM calls.
- HTTP entry points are in `routes/web.php` and `routes/api.php`. MCP route definitions live in `routes/ai.php`. The web app serves `/`, `/panel/*`, and Laravel health at `/up`.
- Frontend code lives in `resources/js`:
  - `resources/js/app.js` is the default app entry.
  - `resources/js/admin.jsx` boots the admin SPA.
  - `resources/js/admin` contains route definitions, pages, shared components, Zustand stores, and tests.
  - `resources/js/admin/generated/providerOptions.js` is generated output; regenerate it instead of editing by hand.
- Styling is in `resources/css/app.css`. Blade views live in `resources/views`.
- Database code lives in `database/migrations`, `database/factories`, and `database/seeders`. Recent schema additions include tenant settings, general settings, Slack user approval, SSH datasource fields, query anchors, and soft deletes on tables / Slack users.
- Deployment and runtime assets live in `docker/`, `docker-compose.yml`, `helm/threadql/`, and `stubs/`.
- The local Docker stack currently includes `threadql`, `worker`, `nginx`, `mysql`, `redis`, `ollama`, and `ssh-tunnel`.

## Build, Test, and Development Commands
- Initial setup: `composer install && npm install`
- Environment bootstrap: `cp .env.example .env && php artisan key:generate`
- Start Docker stack: `make up`
- Open app shell: `make bash`
- Local dev outside Docker: `composer dev`
  - Runs `php artisan serve`, `php artisan queue:listen --tries=1`, `php artisan pail --timeout=0`, and `npm run dev`
- Run migrations: `php artisan migrate` or `make migrate`
- Production assets: `npm run build`
- Backend tests:
  - `./run-tests.sh` is the canonical full-suite command in Docker-oriented local dev
  - `php artisan test` is fine for focused runs
  - `composer test` clears config and runs the Laravel test suite
- Frontend tests: `npm test -- --run`
- Static analysis: `composer stan` or `make stan`
- Style fixes: `./vendor/bin/ecs --fix` or `make ecsfix`
- Useful maintenance commands:
  - `make reload` caches config, clears Relay tool definitions from Redis, and restarts worker / SSH tunnel services
  - `make recent-logs`, `make log-tail`, `make worker-tail`
  - `make ssh-tunnels` to inspect active SSH tunnel state
  - `php artisan threadql:generate-provider-options` after changing provider option definitions

## Architecture Notes
- Slack request flow is tenant-scoped under `/{tenant:uuid}/slack/*`. Signature validation and retry handling are enforced in middleware, and Slack user access is gated by `EnsureSlackUserApproved`.
- Admin APIs live under `/api/admin/*` and use `auth:admin`. `admin.master` protects global resources such as general settings and user management; `admin.tenant` protects tenant-scoped resources.
- The admin SPA currently manages tenants, LLM providers, datasources, tables, definitions, Slack users, tenant settings, and global settings.
- Datasource credentials and Slack credentials are encrypted at the model layer. Datasources also support optional SSH tunneling; do not bypass the existing encrypted attribute and tunnel abstractions.
- Dynamic database access is implemented through `app/Infrastructure/Connectors`, DSN helpers, and database strategy classes for MySQL and Postgres.
- MCP is exposed through the Laravel MCP package and the `ThreadqlServer` definition. The MCP route is intended for internal-network access only.
- Queue-driven behavior is central to the app. Query handling, follow-ups, CSV export, Slack notifications, and schema crawling should stay idempotent and retry-safe.

## Coding Style & Naming Conventions
- PHP uses strict types and PSR-12/ECS conventions. Prefer typed properties, small methods, and early returns.
- Keep HTTP controllers thin. Put business rules in command handlers, services, domain helpers, or infrastructure classes that already own the concern.
- Follow existing naming patterns:
  - Commands end with `Command`; responses end with `Response`
  - Controllers are split by bounded context and HTTP verb (`ListController`, `PutController`, `DeleteController`, etc.)
  - React components use PascalCase; hooks, stores, and helpers use camelCase
- Preserve tenant scoping. Prefer route model binding and middleware-backed authorization over trusting request payload IDs.
- Do not hand-edit generated artifacts when a command exists to rebuild them.

## Testing Guidelines
- PHPUnit is split between `tests/Feature` and `tests/Unit`; mirror the app namespace / folder structure when adding tests.
- React and frontend utility tests use Vitest + Testing Library and live beside the admin pages, components, and JS services.
- Use factories instead of seed dependencies. Fake queues, events, and Slack clients when the behavior under test is dispatch / orchestration.
- Add regression coverage for bug fixes, especially around Slack formatting, pagination, tenant scoping, MCP tools, export flows, and datasource connectivity.
- `run-tests.sh` forces the test environment onto the MySQL `threadql_test` database and raises PHP memory to 256M by default. Do not assume SQLite compatibility.

## Commit & Pull Request Guidelines
- Follow the existing ticket-first commit style, for example `PC-42 clarify auth errors`.
- Keep changes scoped. Separate schema changes, generated assets, and refactors when practical.
- PRs should include a concise summary, linked ticket, commands/tests run, and screenshots for admin UI changes.
- Call out migrations, new env vars, config changes, generated files, Redis/cache implications, or manual rollout steps in the PR description.
