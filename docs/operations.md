# عملیات

## فرایندهای لازم در تولید
| فرایند | دستور |
|---|---|
| وب | php-fpm + nginx (فقط HTTPS) |
| Scheduler | `php artisan schedule:work` (یا cron هر دقیقه `schedule:run`) — اجرای `bell:tick` هر دقیقه |
| Queue | `php artisan queue:work --tries=3 --timeout=60` (چند Worker؛ با Supervisor) |
| مهاجرت | `php artisan migrate --force` سپس `php artisan rbac:sync` |
| اولین مدیر کل | `php artisan platform:create-admin email` |

`bell:tick` ایدمپوتنت است؛ اجرای هم‌زمان چند سرور امن است (`onOneServer` نیاز به cache مشترک Redis دارد، ولی درستی داده به آن وابسته نیست).

## پشتیبان‌گیری
`mysqldump --single-transaction --routines school | gzip` روزانه + binlog؛ نگهداری خارج از سرور؛ **ماهانه بازیابی در محیط جدا آزموده شود** (هنوز آزموده نشده است).

## پایش
`GET /up` (health). شاخص‌های تأخیر/خطا و صف: Telescope/Prometheus — مرحلهٔ ۵.
