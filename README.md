# Chart Jot

A personal trading journal for NinjaTrader 8 traders. Trades are sent
automatically from a custom NT8 AddOn via a REST API, or imported manually
from a CSV export of NT8's Trade Performance → Executions screen. Review
trades, add notes, attach screenshots, and track performance across accounts.

## Stack

- Laravel 13 · PHP 8.5
- Livewire 3 (Volt) · Alpine.js · Tailwind CSS 3.4
- SQLite (dev) · MySQL (prod)
- Laravel Horizon (Redis) for queues/scheduling

## Getting Started

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

php artisan migrate

npm run dev
php artisan serve
```

## NT8 AddOn

The companion NinjaTrader 8 AddOn lives in [`addon/`](addon/) and posts
trades to this app's API. See [`NT8.md`](NT8.md) for the AddOn
specification and [`WEB.md`](WEB.md) for the web application reference
(routes, data model, Livewire components, and key implementation notes).

## Testing

```bash
php artisan test
```
