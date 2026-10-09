# سامانه مدیریت مدارس و آموزش مجازی (School Platform)

سامانه چندمدرسه‌ای (Multi-Tenant) با Laravel 12 + MySQL + Redis. این مخزن در **مرحله ۱ (هسته)** است؛
وضعیت دقیق هر قابلیت (انجام‌شده / آماده‌نشده) در [`docs/status.md`](docs/status.md) آمده است.

| مسیر | محتوا |
|---|---|
| `backend/` | Laravel API (`/api/v1`)، ماژول‌ها در `app/Modules/*`، تست‌ها در `tests/` |
| `docs/` | معماری، ERD، ماتریس مجوزها، امنیت، Realtime/SFU، عملیات، OpenAPI |
| `infra/` | Docker (آزمایش‌نشده در این محیط — به `docs/status.md` نگاه کنید) |

## اجرای توسعه
```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
# توسعهٔ سبک با SQLite: DB_CONNECTION=sqlite در .env  |  یا MySQL از طریق docker compose (زیر)
php artisan migrate --seed          # RBAC + (فقط local) مدرسهٔ نمونه؛ رمز تصادفی چاپ می‌شود
php artisan platform:create-admin you@example.com   # رمز به‌صورت تعاملی پرسیده می‌شود
php artisan serve                   # API
php artisan schedule:work           # موتور زنگ (bell:tick هر دقیقه)
php artisan queue:work              # صف
```

## تست
```bash
cd backend && php artisan test      # SQLite در حافظه؛ ۳۷ تست، شامل جداسازی مدارس، تداخل برنامه، زنگ و idempotency
```

## Docker (MySQL 8 + Redis + MinIO + queue + scheduler)
```bash
cd infra && cp ../backend/.env.example ../backend/.env   # مقادیر را تنظیم کنید
docker compose up -d --build
docker compose exec app php artisan key:generate && docker compose exec app php artisan migrate --seed --force
```
