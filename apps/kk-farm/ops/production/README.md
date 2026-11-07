# Production deployment (D-045)

Server layout: `/srv/kkfarm/{docker-compose.yml, .env, app/, backups/}`.

- `app/` is a copy of `apps/kk-farm` (uploaded with rsync, no `vendor`, `node_modules` or `.env`).
- `.env` holds the secrets and is created on the server only; it is never copied from a laptop or committed.
- The image is built on the server; the entrypoint runs migrations and caches config on every start.
- HTTPS: FrankenPHP (Caddy) gets a Let's Encrypt certificate for `SERVER_NAME`. Without a domain we use
  `103-163-48-203.sslip.io`, which resolves to the server's IP. Switch to a real domain by changing `SERVER_NAME`.
- Backups: `/srv/kkfarm/backups`, nightly at 02:30 server time, 14 days.

## First owner
Run on the server and type the password at the prompt:

    cd /srv/kkfarm && docker compose exec app php artisan kkfarm:create-owner

## Update
From a laptop with the deploy key, re-run the rsync and on the server:

    cd /srv/kkfarm && docker compose up -d --build
