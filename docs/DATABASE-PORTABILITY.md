# تشغيل المشروع على MySQL

المشروع بقى محايد بالنسبة للداتابيز: نفس الكود يشتغل على **PostgreSQL** و**MySQL/MariaDB** و**SQLite** (الأخيرة للاختبارات بس).

---

## ١. إيه اللي اتغيّر

قبل التعديل كان في ٤ مواضع بتستخدم SQL مخصوص لـ PostgreSQL، وده كان بيمنع التشغيل على MySQL — **وكمان بيكسر مجموعة الاختبارات** لأنها بتشتغل على SQLite (شوف `phpunit.xml`).

| الملف | كان | بقى |
|---|---|---|
| `app/Support/DbExpr.php` | — | **ملف جديد**: بيرجّع صيغة SQL مناسبة لكل driver |
| `app/Domains/AccountManager/AccountManagerController.php:72` | `TO_CHAR(...)` | `DbExpr::yearMonth('created_at')` |
| `app/Domains/Audit/AuditController.php:73` | `TO_CHAR(...)` | `DbExpr::yearMonth('created_at')` |
| `app/Console/Commands/SendBirthdayReminders.php` | `whereRaw('EXTRACT(...)')` | `whereMonth()` + `whereDay()` (مدمجين في Laravel) |
| `database/migrations/2026_07_12_000001_change_proof_file_url_to_json.php` | `ALTER COLUMN ... TYPE json` (Postgres خالص) | Schema builder + تحويل آمن للبيانات القديمة |

بعد التعديل مفيش أي `DB::statement` أو `whereRaw` متبقي في المشروع.

---

## ٢. خطوات التشغيل

### أ) إنشاء الداتابيز

```sql
CREATE DATABASE shadapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'shadapp'@'localhost' IDENTIFIED BY 'ضع_كلمة_سر_قوية';
GRANT ALL PRIVILEGES ON shadapp.* TO 'shadapp'@'localhost';
FLUSH PRIVILEGES;
```

> `utf8mb4` ضروري عشان النصوص العربية والإيموجي. متستخدمش `utf8` القديمة.

### ب) تعديل `.env`

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=shadapp
DB_USERNAME=shadapp
DB_PASSWORD=كلمة_السر
```

### ج) التشغيل

```bash
composer install
php artisan key:generate      # لو الـ APP_KEY فاضي
php artisan migrate:fresh
php artisan db:seed           # لو فيه seeders محتاجها
php artisan test              # المفروض تشتغل دلوقتي (كانت مكسورة قبل كده)
```

---

## ٣. المتطلبات

- **MySQL 8.0+** أو **MariaDB 10.6+** — النسخ الأقدم دعمها لأعمدة JSON ضعيف
- امتداد `pdo_mysql` مفعّل في PHP

---

## ٤. فروق سلوكية لازم تعرفها

دي مش أخطاء، دي اختلافات حقيقية بين المحركين هتلاحظها:

### أ) البحث هيبقى غير حساس لحالة الأحرف — وده تحسين

في `ClientController.php:31` و`AccountManagerController.php:30` البحث بيستخدم `LIKE`.

- **على Postgres**: البحث عن `ahmed` **مش** هيلاقي `Ahmed` (سلوك حالي غير مريح)
- **على MySQL** مع `utf8mb4_unicode_ci`: هيلاقيه

يعني البحث هيشتغل أحسن على MySQL.

### ب) الإيميلات — انتبه لدي

تسجيل الدخول بيدوّر بـ `where('email', $request->email)` من غير أي توحيد لحالة الأحرف (`AuthController.php:21,43,62` و`SubUserController.php:23`).

- **على Postgres**: `Ahmed@x.com` و`ahmed@x.com` حسابين **مختلفين**
- **على MySQL**: نفس الحساب، والـ unique index هيرفض التاني

على داتابيز جديدة فاضية ده أأمن وأصح. بس **الحل الجذري** هو توحيد الإيميل لحروف صغيرة عند الحفظ وعند البحث، عشان السلوك يبقى واحد على أي محرك. متعملتش دلوقتي لأنها بتلمس مسارات تسجيل الدخول ومينفعش أغيّرها من غير اختبار فعلي — لو عايزها، قولي وأنفّذها.

### ج) حدود التاريخ

`personal_access_tokens.expires_at` نوعه `timestamp`، وفي MySQL ده محدود بسنة ٢٠٣٨. مش مشكلة عملية لتوكنات بتنتهي خلال ٢٤ ساعة، بس تستاهل تتعرف. باقي أعمدة التواريخ (`date_of_birth`, `start_date`, `end_date`) نوعها `date` و`scheduled_at` نوعه `dateTime` — كلها مفيهاش الحد ده.

---

## ٥. حاجة منفصلة لكن مهمة

`config/database.php:20` الافتراضي فيه `sqlite`:

```php
'default' => env('DB_CONNECTION', 'sqlite'),
```

يعني لو الـ `.env` اتنسي فيه `DB_CONNECTION`، التطبيق **مش هيقع** — هيشتغل عادي على ملف SQLite فاضي وكأن مفيش بيانات. ده فخ حقيقي في النشر. يُفضّل تغييره للمحرك اللي بتستخدمه فعلاً عشان الغلط يبان بدري.
