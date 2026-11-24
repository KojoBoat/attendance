# A web app for taking attendance of students in a lecture hall using qrcode

## Requirements

- PHP 8.2 or newer (tested on 8.4) with the `gd`, `mbstring`, `openssl`, `pdo_mysql` and `xml` extensions
- Composer 2
- MySQL / MariaDB

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan passport:keys
php artisan passport:client --personal
php artisan serve
```

Run the tests with `php vendor/bin/phpunit`.

New QR codes are saved as SVG files in `public/images/codes/`. Existing PNG codes keep working.
