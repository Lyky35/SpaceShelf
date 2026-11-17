# 🚀 SpaceShelf

**SpaceShelf** is a browser-based space strategy MMO — a clone of [OGame](https://ogame.org) — built on the codebase of [OGameX](https://github.com/lanedirt/OGameX), an open-source OGame redesign powered by Laravel.

Colonize planets, build economies, research technologies, construct fleets, and battle rivals across the galaxy.

## 📦 Based on OGameX

This project started as a direct clone of [`lanedirt/OGameX`](https://github.com/lanedirt/OGameX) (MIT-licensed), rebranded as **SpaceShelf**. Thanks to the OGameX team for the excellent foundation:

- Full game engine: planets, buildings, research, shipyard, defense, galaxy view, highscores, messages, chat
- Fleet missions (transport, colonization, espionage, attack, recycling, moon destruction, ACS)
- Battle engine with a high-performance Rust implementation (PHP FFI, ~200x faster than pure PHP)
- Moons, debris fields, wreck fields, space dock, expeditions, alliances, admin panel

The upstream is tracked as the `upstream` git remote to allow pulling future improvements.

## 🖥️ Quick start (Docker)

> Ports 80/443/3306 may be taken on your machine, so `.env` ships with alternative ports.

```bash
cp .env.example .env   # adjust ports in .env if needed (HTTP_PORT, HTTPS_PORT, PHPMYADMIN_PORT, DB_EXTERNAL_PORT)
docker compose up -d --build
```

The first run can take **up to 10 minutes** (composer install + Rust battle engine compilation). Be patient.

| Service | URL |
|---|---|
| Game | http://localhost:9081 |
| phpMyAdmin | http://localhost:8080 |

Create an account to start playing — the **first registered account becomes admin**.

### Useful commands

Run artisan commands inside the app container:

```bash
# Assign admin role to a user
docker compose exec spaceshelf-app php artisan spaceshelf:admin:assign-role {username}

# Remove admin role
docker compose exec spaceshelf-app php artisan spaceshelf:admin:remove-role {username}

# Reset DB and seed fresh
docker compose exec spaceshelf-app php artisan migrate:fresh --seed
```

### Development notes

- PHP files reload instantly in the app container; re-run `npm run build` (or let Vite rebuild assets) after frontend changes.
- Tests: `docker compose exec spaceshelf-app php artisan test`
- Static analysis: `docker compose exec spaceshelf-app ./vendor/bin/phpstan`

## 🔄 Upgrading from upstream

```bash
git fetch --unshallow upstream   # if cloned shallow, first fetch full history
git merge upstream/main          # resolve conflicts as needed
docker compose up -d --build --force-recreate --remove-orphans
```

The container entrypoint automatically re-runs migrations and rebuilds caches on startup.

## ⚠️ Disclaimer

This project is a non-commercial, fan-based learning project. All rights and concepts related to OGame are owned by **GameForge GmbH** — please support them by playing the official game at [ogame.org](https://ogame.org). SpaceShelf contains no OGame artwork or proprietary content from GameForge beyond what is present in the upstream OGameX project.

The SpaceShelf source code (OGameX-derived) is licensed under the MIT license — see [LICENSE](LICENSE).
