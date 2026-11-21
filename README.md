# Inkwell

A simple blog in plain PHP (no framework), MySQL and TypeScript. Architecture, rules and decisions: `CLAUDE.md`. Build order: `docs/IMPLEMENTATION_PLAN.md`.

## Setup

```
composer install && npm install
cp .env.example .env.local           # then set the DB_* passwords and ADMIN_PASSWORD
composer db:up                       # MySQL 8.4 in Docker on 127.0.0.1:3308
php bin/migrate.php                  # schema + seed (admin from ADMIN_EMAIL / ADMIN_PASSWORD)
npm run build                        # frontend/src/main.ts -> public/assets/app.js
composer serve                       # http://localhost:8000
```

Other commands: `composer test` (PHPUnit, unit), `npm run typecheck` (strict TS), `composer db:down`.

The admin logs in at `/login` with `ADMIN_EMAIL` / `ADMIN_PASSWORD`. Users register at `/register`.
