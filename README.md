# AASRA IMPACT

AASRA IMPACT is a Laravel based project monitoring and impact management portal for Aasraa Trust. The current release includes the operational foundation and Phase 2 indicator system: secure staff login, role and scope controls, programme and centre management, guided KPI configuration, target management, generated data-entry controls, private evidence, approval decisions, dashboards, CSV reports, and deterministic demonstration data.

## Requirements

- PHP 8.3 or newer with PDO MySQL, mbstring, intl, fileinfo, openssl, and zip
- Composer 2
- MySQL 8
- Node.js 20 or newer and npm

This repository includes workspace local PHP and MySQL binaries under the ignored `.runtime/` directory for development on this machine. Production must use the host's maintained PHP and MySQL services.

## Install

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create an empty MySQL database and user, then update `DB_*` in `.env`.

```bash
php artisan migrate
php artisan db:seed
php artisan filament:assets
npm run build
php artisan serve
```

Open `http://127.0.0.1:8000/login`. Demo users are listed in [DEMO_DATA.md](DEMO_DATA.md); their password is the private `DEMO_PASSWORD` value in `.env`. The Filament administration panel is at `/admin` and accepts administrators only.

Run tests and formatting with:

```bash
php artisan test --compact
vendor/bin/pint --format agent
```

Remove only demo tagged data before operational use:

```bash
php artisan impact:clear-demo-data
```

Never run that command against operational records that were incorrectly marked as demo. Review [DEMO_DATA.md](DEMO_DATA.md) before clearing.

## Documentation

- [Architecture](ARCHITECTURE.md)
- [Database schema](DATABASE_SCHEMA.md)
- [Features and current scope](FEATURES.md)
- [User guide](USER_GUIDE.md)
- [Administrator guide](ADMIN_GUIDE.md)
- [Aggregation rules](AGGREGATION_RULES.md)
- [Testing](TESTING.md)
- [Demo data](DEMO_DATA.md)
- [Hostinger deployment](DEPLOYMENT_HOSTINGER.md)
