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
| پاک‌سازی داده | `php artisan retention:run [--dry-run]` | روزانه ۰۲:۳۰ توسط Scheduler |
| پویشگر ویروس | `clamd` (TCP 3310) + `freshclam` | بدون آن فایل‌ها `skipped` ثبت می‌شوند |
| مهاجرت | `php artisan migrate --force` سپس `php artisan rbac:sync` | |
| اولین مدیر کل | `php artisan platform:create-admin email` | رمز تعاملی؛ هیچ رمزی در مخزن نیست |

`bell:tick` ایدمپوتنت است؛ اجرای هم‌زمان چند سرور امن است. اگر SFU یا سرویس دیگری موقتاً خراب باشد، همان رویداد ناموفق در دقیقهٔ بعد دوباره تلاش می‌شود و رویدادهای دیگر متوقف نمی‌شوند؛ رویداد قدیمی‌تر از ۱۰ دقیقه بی‌صدا بسته می‌شود (زنگ کهنه گمراه‌کننده است).

## متغیرهای محیطی مهم
نمونهٔ کامل در `backend/.env.example` (بدون هیچ راز). `SCAN_PROVIDER` (`none|clamav`) + `CLAMAV_HOST/PORT/SOCKET` + `SCAN_FAIL_CLOSED`، `EGRESS_S3_*` (ضبط)، `MEDIA_PROVIDER` (`none|livekit`)، `LIVEKIT_*`، `TURN_URL`/`TURN_SECRET`، `AI_PROVIDER`/`AI_API_KEY`، `FILES_DISK=s3` + `AWS_*`، `REVERB_*`، `FCM_*`، `CORS_ALLOWED_ORIGINS`، `TRUSTED_PROXIES`، `REGISTER_PER_HOUR`. سرویس پیکربندی‌نشده در `/platform/health` و UI صراحتاً «پیکربندی نشده» نمایش داده می‌شود.

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

## اپ اندروید
ساخت (آزموده‌شده، روی لینوکس با JDK 21 و Android SDK 36):
```bash
export ANDROID_HOME=/opt/android          # cmdline-tools + platforms;android-36 + build-tools;36.0.0 و 28.0.3
cd frontend && flutter build apk --release \
  --dart-define=API_BASE=https://YOUR-HOST/api/v1 --dart-define=REVERB_HOST=YOUR-HOST --dart-define=REVERB_PORT=443 --dart-define=REVERB_KEY=<REVERB_APP_KEY>
```
- خروجی: `frontend/build/app/outputs/flutter-apk/app-release.apk` (۱۰۲ مگابایت، شامل arm64/armv7/x86_64؛ برای فروشگاه از `--split-per-abi` یا `flutter build appbundle` استفاده کنید).
- **امضا:** این APK با کلید debug امضا می‌شود (فقط برای آزمون). برای انتشار یک keystore واقعی بسازید و در `android/app/build.gradle.kts` به `signingConfigs.release` وصل کنید؛ keystore و رمزش هرگز در مخزن نباید باشد.
- دسترسی‌های مانیفست: اینترنت، دوربین، میکروفون، بلوتوث (هدست)، اعلان.
- **آزموده نشده روی دستگاه/شبیه‌ساز:** در محیط توسعه KVM نبود؛ فقط ساخت موفق، امضا و محتوای مانیفست بررسی شد. جریان کلاس زنده/اعلان فشاری روی اندروید واقعی باید دستی تست شود.

## آزمون ضبط کلاس با Egress واقعی (انجام‌شده)
ضبط کلاس در این پروژه **با LiveKit Egress واقعی** آزموده شده است (`e2e/tests/11-recording.spec.ts`): معلم از UI ضبط را روشن می‌کند، دانش‌آموز نوار «این کلاس در حال ضبط است» را می‌بیند، پس از خاموش کردن یک MP4 (H.264 1280×720 + AAC، حدود ۱۶ ثانیه، شامل تصویر دوربین ساختگی معلم) در باکت S3 ظاهر می‌شود، `ffprobe` وجود تصویر و صدا را تأیید می‌کند و دانش‌آموز به آن دسترسی ندارد.

آنچه برای این آزمون راه‌اندازی شد (بدون Docker؛ در تولید همان کار را سرویس‌های `livekit`/`egress` در compose می‌کنند):
1. `livekit-server --config` با **redis مشترک**، `node_ip` برابر IP واقعی ماشین (نه 127.0.0.1؛ با loopback ICE کروم Egress برقرار نمی‌شد) و `webhook.urls` به `/api/v1/webhooks/livekit` با همان کلید API.
2. ایمیج رسمی `livekit/egress:v1.8.4` با `scripts/pull_image.py` بدون daemon باز و داخل `chroot` اجرا شد (`/entrypoint.sh`، pulseaudio + Xvfb + Chrome داخل ایمیج).
3. باکت سازگار با S3: `moto_server` (پایتون). برای تولید MinIO/S3 واقعی.
4. قالب ضبط **خودمیزبان** (`infra/egress-template/`، با `livekit-client` وندورشده) چون قالب پیش‌فرض از `egress-composite.livekit.io` بارگذاری می‌شود. آدرسش در `EGRESS_TEMPLATE_URL`.
5. متغیرهای `AWS_CA_BUNDLE`/پروکسی محیط نباید به Egress نشت کنند (در آزمون اول باعث شکست آپلود شد).

یافته‌های این آزمون که اصلاح شدند: (۱) بستهٔ `league/flysystem-aws-s3-v3` نصب نبود و دیسک S3 اصلاً کار نمی‌کرد → نصب شد؛ (۲) شرکت‌کنندگان در کلاس نوار واضحی برای «در حال ضبط» نداشتند → افزوده شد؛ (۳) قالب میزبانی‌شدهٔ LiveKit در شبکهٔ بسته در دسترس نیست → قالب خودمیزبان.
**هنوز آزموده نشده:** ضبط با شرکت‌کنندگان زیاد، ضبط طولانی (ساعت‌ها)، فضا/CPU لازم برای چند ضبط هم‌زمان (Egress ترکیبی چند هسته می‌خواهد).
