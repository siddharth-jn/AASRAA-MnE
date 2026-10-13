# Hostinger deployment

Production deployment has not yet been tested on Aasraa's Hostinger account. Confirm the selected plan supports PHP 8.3+, MySQL 8, Composer, SSH or a deployment workflow, cron, and a document root that can point to Laravel's `public` directory.

Keep the repository, `.env`, `vendor`, and `storage/app/private` outside the public document root. Expose only `public/index.php`, compiled assets, and other public files. Configure `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://impact.aasra.me`, a generated `APP_KEY`, MySQL credentials, mail settings, and a private filesystem disk.

Build and prepare a release:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan filament:assets
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Do not link private evidence into `public/storage`. Ensure `storage` and `bootstrap/cache` are writable by the PHP process. Configure HTTPS, secure cookies, regular database and private evidence backups, and retention checks. A cron entry may run `php artisan schedule:run` every minute if scheduled reminders are enabled later. Test restore procedures and every role before switching from demo data to operational use.
