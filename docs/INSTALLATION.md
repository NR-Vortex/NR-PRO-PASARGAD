# راه‌اندازی محیط توسعه NRX VPN

> **این راهنما نصب روی سرور Production نیست.** نسخه جاری تنها برای بررسی و توسعه محلی منتشر شده است. هنوز نصب‌کننده یک‌خطی تأییدشده، بسته Docker تولید و فرآیند Migration امن برای نصب عمومی منتشر نشده‌اند.

## پیش‌نیاز

- PHP 8.2+ و Composer
- اکستنشن‌های PHP متناسب با سناریو: PDO PostgreSQL، OpenSSL و cURL
- PostgreSQL فقط برای سناریوهایی که واقعاً به دیتابیس نیاز دارند
- Redis برای Queue و Worker
- ترمینال PowerShell یا Bash

## دریافت شاخه پیش‌نمایش

```bash
git clone https://github.com/NR-Vortex/NR-PRO-PASARGAD.git
cd NR-PRO-PASARGAD
git switch release/nrx-source-preview-20261031
composer install
```

`composer install` باید از روی `composer.lock` اجرا شود؛ برای نصب عادی `composer update` لازم نیست.

## فایل پیکربندی

در Windows:

```powershell
Copy-Item .env.example .env
```

در Linux:

```bash
cp .env.example .env
```

فایل `.env` باید خارج از Git بماند. در این فایل از رمز، توکن و کلید واقعیِ محیط Production استفاده نکنید. مقادیر مثال `DB_SSLMODE=disable` و `REDIS_PORT=6380` صرفاً برای تنظیم رایج محیط توسعه محلی‌اند و ممکن است به شرایط سیستم شما بخورند یا نخورند.

برای تست HTTP پایه نیازی به راه‌اندازی Worker یا اجرای Migration نیست.

## اجرای HTTP محلی

```bash
php -S 127.0.0.1:8000 -t public
```

نشانی `http://127.0.0.1:8000/health` باید پاسخ وضعیت اولیه برنامه را برگرداند. این نتیجه **به‌تنهایی تأیید نمی‌کند** که Redis، دیتابیس یا تلگرام آماده‌اند.

## تست‌های جداگانه

در پروژه ابزارهای بررسی محلی وجود دارند. پیش از اجرای آن‌ها کدشان را بخوانید و مطمئن شوید به زیرساخت تولید متصل نمی‌شوند.

```bash
php scripts/local/worker-isolated-check.php --offline-worker
```

این تست به Redis محلی و محیط توسعه ایزوله نیاز دارد و ممکن است کلیدهای آزمایشی موقت ایجاد و پاک کند؛ آن را در VPS اصلی اجرا نکنید. اجرای Migration نیز به رویه مستقل تأییدشده نیاز دارد.

## نصب مستقیم سرور

**در این نسخه پشتیبانی نمی‌شود.** دستور نصب مستقیم پس از آزمون امنیت، فایل‌های Compose تولید، بررسی متغیرهای محیطی، بکاپ، Rollback، TLS، Permissionها و اجرای تست روی یک VPS تمیز ارائه خواهد شد. تا آن زمان هیچ اسکریپت `curl | bash` به‌عنوان نصب‌کننده رسمی NRX معرفی نمی‌شود.
