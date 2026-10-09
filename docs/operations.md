# عملیات

## فرایندهای لازم در تولید
| فرایند | دستور | نکته |
|---|---|---|
| وب | php-fpm + nginx | فقط HTTPS (TLS در لود‌بالانسر) |
| Scheduler | `php artisan schedule:work` | `bell:tick` هر دقیقه، یادآورها، انقضای تلاش‌های آزمون |
| Queue | `php artisan queue:work redis --queue=default,broadcasts --tries=3 --timeout=60` | چند Worker با Supervisor |
| WebSocket | `php artisan reverb:start` | پشت `/app` در nginx |
| SFU | LiveKit (`infra/livekit.yaml`) | پورت‌های UDP/TCP عمومی؛ بدون آن کلاس زنده کار نمی‌کند |
| TURN | coturn (`infra/turnserver.conf`) | برای کاربران پشت NAT/فایروال سخت |
| مهاجرت | `php artisan migrate --force` سپس `php artisan rbac:sync` | |
| اولین مدیر کل | `php artisan platform:create-admin email` | رمز تعاملی؛ هیچ رمزی در مخزن نیست |

`bell:tick` ایدمپوتنت است؛ اجرای هم‌زمان چند سرور امن است. اگر SFU یا سرویس دیگری موقتاً خراب باشد، همان رویداد ناموفق در دقیقهٔ بعد دوباره تلاش می‌شود و رویدادهای دیگر متوقف نمی‌شوند؛ رویداد قدیمی‌تر از ۱۰ دقیقه بی‌صدا بسته می‌شود (زنگ کهنه گمراه‌کننده است).

## متغیرهای محیطی مهم
نمونهٔ کامل در `backend/.env.example` (بدون هیچ راز). `MEDIA_PROVIDER` (`none|livekit`)، `LIVEKIT_*`، `TURN_URL`/`TURN_SECRET`، `AI_PROVIDER`/`AI_API_KEY`، `FILES_DISK=s3` + `AWS_*`، `REVERB_*`، `FCM_*`، `CORS_ALLOWED_ORIGINS`، `TRUSTED_PROXIES`، `REGISTER_PER_HOUR`. سرویس پیکربندی‌نشده در `/platform/health` و UI صراحتاً «پیکربندی نشده» نمایش داده می‌شود.

## استقرار با Docker
`infra/docker-compose.yml` (app/queue/scheduler/reverb/web/mysql/redis/minio/livekit/turn)، `infra/Dockerfile`، `infra/Dockerfile.web` (بیلد Flutter + nginx با SPA fallback). **این فایل‌ها در محیط توسعه اجرا نشدند (daemon داکر نبود)** و باید در اولین بیلد بازبینی شوند. مقدارهای راز فقط از `backend/.env` و متغیرهای شل می‌آیند.

## پشتیبان‌گیری و بازیابی
- پایگاه‌داده: `mysqldump --single-transaction --routines school | gzip > school-$(date +%F).sql.gz` روزانه + binlog؛ نگهداری خارج از سرور.
- فایل‌ها: ریپلیکیشن/نسخه‌بندی باکت S3 (یا `mc mirror` برای MinIO).
- بازیابی: `gunzip -c file.sql.gz | mysql school` سپس `php artisan migrate --force`.
- **بازیابی هرگز در این محیط آزموده نشده است**؛ ماهانه در محیط جدا تمرین شود.

## تست بار (اندازه‌گیری‌شده، نه ادعای ظرفیت)
ابزار: `node scripts/loadtest.mjs <base> <ثانیه> <هم‌زمانی‌ها>`؛ ترکیب درخواست‌های خواندنی واقعی (اعلان‌ها، برنامه، تکالیف، آزمون‌ها، نمرات، فرزندان، لیست دانش‌آموزان، تحلیل) با ۴ کاربر واقعی.
نتیجهٔ اجرا روی **همین محیط توسعه** (۴ هسته که مولد بار هم روی آن بود، سرور داخلی `php -S` با ۸ Worker، MariaDB و Redis محلی، داده‌های کم، ۱۵ ثانیه برای هر مرحله):

| هم‌زمانی | درخواست/ثانیه | p50 | p95 | p99 | خطا |
|---:|---:|---:|---:|---:|---:|
| ۵ | ۱۲۵٫۵ | ۳۷ms | ۶۶ms | ۸۲ms | ۰ |
| ۲۰ | ۱۳۰٫۸ | ۱۵۰ms | ۲۱۵ms | ۲۴۹ms | ۰ |
| ۵۰ | ۱۳۳٫۶ | ۳۶۶ms | ۴۵۱ms | ۵۲۰ms | ۰ |

برداشت: توان عملیاتی این پیکربندی حدود ۱۳۰ درخواست/ثانیه است و با افزایش هم‌زمانی فقط تأخیر زیاد می‌شود. این عدد **ظرفیت تولید نیست**: php-fpm+opcache، MySQL جدا و چند Worker نتیجهٔ دیگری می‌دهند. تست بار WebRTC (تعداد دانش‌آموز هم‌زمان در کلاس) و WebSocket اجرا نشده است؛ هیچ عدد ظرفیتی برای آن‌ها ادعا نمی‌شود. پیش از عرضه باید روی سخت‌افزار هدف با داده‌های واقعی تکرار شود.

## پایش
`GET /up` (health) و `/platform/health` (وضعیت SFU، AI، صف). شاخص‌های تأخیر/خطا (Prometheus یا مشابه) پیاده نشده است.
