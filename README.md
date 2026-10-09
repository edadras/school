# سامانه جامع مدیریت مدارس و آموزش مجازی هوشمند

سامانهٔ چندمدرسه‌ای (Multi-Tenant)، فارسی/راست‌به‌چپ، با تم سفید و آبی روشن.
**Flutter** (وب + اندروید، واکنش‌گرا) · **Laravel 12** (API نسخه‌دار) · **MySQL** · **Redis** · **Reverb** (WebSocket) · **LiveKit SFU + TURN** (WebRTC) · ذخیره‌سازی سازگار با S3 · OpenAPI.

وضعیت دقیق و صادقانهٔ هر قابلیت (انجام‌شده / آزموده‌نشده / ساخته‌نشده): **[`docs/status.md`](docs/status.md)**

| مسیر | محتوا |
|---|---|
| `backend/` | Laravel API (`/api/v1`)، ماژول‌ها در `app/Modules/*`، تست‌ها در `tests/` |
| `frontend/` | اپ Flutter (وب/اندروید) |
| `e2e/` | تست‌های Playwright روی پشتهٔ واقعی (MariaDB، Redis، Reverb، LiveKit، صف، Scheduler) |
| `docs/` | وضعیت، معماری و ERD، ماتریس مجوز، امنیت، Realtime/SFU، عملیات (+تست بار)، OpenAPI |
| `infra/` | Docker/nginx/LiveKit/coturn (**اجرا نشده** — `docs/status.md`) |
| `scripts/` | `e2e-stack.sh` (پشتهٔ آزمایشی)، `loadtest.mjs`، `gen_openapi.py` |

## اجرای توسعه (بدون Docker)
پیش‌نیاز: PHP 8.3 (+ pdo_mysql/sqlite, redis, intl, gd, zip, mbstring)، Composer، MySQL/MariaDB یا SQLite، Redis، Flutter 3.x، (برای کلاس زنده) `livekit-server`.
```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate     # مقادیر را تنظیم کنید؛ هیچ رازی در مخزن نیست
php artisan migrate && php artisan rbac:sync
php artisan platform:create-admin you@example.com     # رمز به‌صورت تعاملی پرسیده می‌شود
php artisan serve                                     # API
php artisan queue:work redis --queue=default,broadcasts
php artisan schedule:work                             # موتور زنگ (bell:tick هر دقیقه) و یادآورها
php artisan reverb:start                              # WebSocket

cd ../frontend
flutter pub get
flutter run -d chrome --dart-define=API_BASE=http://localhost:8000/api/v1 \
  --dart-define=REVERB_HOST=localhost --dart-define=REVERB_PORT=8080 --dart-define=REVERB_KEY=<REVERB_APP_KEY>
# بیلد وب:  flutter build web --release --dart-define=...   (سرور باید به index.html fallback کند؛ infra/nginx.conf)
```
کلاس زنده: `MEDIA_PROVIDER=livekit` و `LIVEKIT_*` را تنظیم کنید؛ بدون آن، رابط «پیکربندی نشده» را صادقانه نشان می‌دهد.

## تست
```bash
cd backend && php artisan test         # ۱۵۵ تست / ۱۰۶۵ assertion — روی SQLite و MariaDB 10.11 سبز

# E2E روی پشتهٔ واقعی (مرورگر Chromium، LiveKit، Reverb، صف و Scheduler)
LIVEKIT_BIN=/path/to/livekit-server scripts/e2e-stack.sh start
cd e2e && npm ci && npx playwright test
```
پوشش سناریوهای پذیرش: `docs/status.md`.

## Docker (آزمایش‌نشده — بازبینی در اولین بیلد)
```bash
cd infra
export DB_PASSWORD=… AWS_ACCESS_KEY_ID=… AWS_SECRET_ACCESS_KEY=… LIVEKIT_KEYS="key: secret" TURN_SECRET=… REVERB_HOST=school.example.com REVERB_KEY=…
cp ../backend/.env.example ../backend/.env        # و مقادیر را تنظیم کنید
docker compose up -d --build
```
جزئیات: `docs/operations.md`.
