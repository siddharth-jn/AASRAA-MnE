# AASRA IMPACT

AASRA IMPACT is a Laravel based project monitoring and impact management portal for Aasraa Trust. The current release completes Phases 1–5: secure staff access, configurable indicators and targets, evidence-backed submissions and approvals, dashboards and data quality monitoring, approved-only reporting, CSV/XLSX migration, exports, and deterministic demonstration data.

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

Run Laravel's scheduler every minute in production so reporting deadline reminders are delivered:

```cron
* * * * * cd /path/to/application && php artisan schedule:run >> /dev/null 2>&1
```

Open `http://127.0.0.1:8000/login`. Demo users are listed in [DEMO_DATA.md](DEMO_DATA.md); their password is the private `DEMO_PASSWORD` value in `.env`. The Filament administration panel is at `/admin` and accepts administrators only.

Set `EMAIL_NOTIFICATIONS=true` after configuring Laravel mail settings to send workflow emails in addition to in-app notifications. Password reset mail uses the configured Laravel mailer. Core reporting and approval workflows continue to work with email disabled.

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

- [Database dictionary](AASRAA_DATABASE_DICTIONARY.docx)
- [Hostinger deployment dependencies](AASRAA_HOSTINGER_DEPLOYMENT_DEPENDENCIES.docx)
- [Comprehensive operating manual](AASRAA_OPERATING_MANUAL.docx)
- [Vercel production assessment](AASRAA_VERCEL_PRODUCTION_ASSESSMENT.docx)
- [Architecture](ARCHITECTURE.md)
- [Database schema](DATABASE_SCHEMA.md)
- [Features and current scope](FEATURES.md)
- [User guide](USER_GUIDE.md)
- [Administrator guide](ADMIN_GUIDE.md)
- [Aggregation rules](AGGREGATION_RULES.md)
- [Testing](TESTING.md)
- [Demo data](DEMO_DATA.md)
- [Hostinger deployment](DEPLOYMENT_HOSTINGER.md)
