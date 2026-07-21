# AI PDF Generator

WordPress-плагін, який генерує PDF-документи (інвойси, квитки, сертифікати, бейджі, листи-подяки тощо) із HTML-шаблонів, створених через **Google Gemini API**. Опишіть документ природною мовою — плагін згенерує шаблон, дасть його візуально відредагувати й автоматично рендеритиме готовий PDF за подіями плагінів (WooCommerce, Amelia, форми та ін.).

## Можливості

- **AI-генерація шаблонів (Playground).** Текстовий запит → Gemini повертає HTML-каркас + структуровані `editable_fields`.
- **Режим чернетки + чат-уточнення (Refine).** Згенерований шаблон спершу показується як чернетка (пост не створюється). Кнопка «Уточнити» шле поточний макет разом з інструкцією назад у Gemini для правок; «Зберегти шаблон» фіналізує його як CPT.
- **Референс-зображення.** До запиту можна прикріпити зразок дизайну (через медіатеку WordPress) — він передається в Gemini як `inline_data`, і AI відтворює його макет, кольори та стиль.
- **Візуальний редактор (без коду).** Замість сирого HTML — динамічні поля: WP Color Picker для кольорів, `input`/`textarea` для текстів. Живе превю оновлюється миттєво. JSON — джерело правди.
- **Смарт-тригери (65 подій).** Каталог тригерів за реальними іменами хуків (WooCommerce, EDD, Amelia, Bookly, TEC, 9 плагінів форм, LMS, членства, донати, CRM, ядро WP). У списку показуються **лише** тригери активних плагінів; кожен несе власні контекстні плейсхолдери.
- **Смарт-плейсхолдери.** `{{client_name}}`, `{{order_id}}`, `{{order_total}}`, `{{qr_code}}` тощо — підставляються реальними даними події під час рендеру.
- **Брендинг.** Логотип (медіатека), основний колір і реквізити компанії виносяться в налаштування й доступні як `{{logo_url}}`, `{{brand_color}}`, `{{company_name}}`, `{{company_address}}`, `{{company_email}}` — без хардкоду в шаблонах.
- **Рендер у PDF — mPDF під капотом.** Повна підтримка UTF-8/кирилиці «з коробки» (DejaVu Sans), кастомні розміри сторінок (A4, Letter, `800x400` px), таблична верстка та інлайнові стилі.
- **Доставка.** `attach_to_email` (лист із вкладенням) або `download_link` (кнопка на сторінці подяки WooCommerce / під формою CF7 та Elementor / шорткод `[aipdf_download_button]` для redirect-сценаріїв Amelia).
- **Garbage Collection.** WP Cron щодня видаляє PDF, старіші за налаштований термін зберігання.
- **Audit Log.** Файловий логер із ротацією (5 МБ) — помилки API, рендеру й доставки видно в адмінці.

## Вимоги

- WordPress 6.0+
- PHP 8.0+
- Composer (для встановлення mPDF)
- Ключ Google Gemini API ([Google AI Studio](https://aistudio.google.com/))

## Встановлення

1. Скопіюйте папку `ai-pdf-generator/` у `wp-content/plugins/`.
2. У папці плагіна встановіть залежності:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Активуйте плагін у **Плагіни → Встановлені**.
4. Відкрийте **AI PDF → Генератор і налаштування → Налаштування**, вставте Gemini API Key (або задайте його константою в `wp-config.php` — вона має пріоритет над опцією в БД):
   ```php
   define( 'AIPDF_GEMINI_API_KEY', 'ваш-ключ' );
   ```
5. За потреби змініть **Модель Gemini** (за замовчуванням `gemini-flash-latest` — самооновлюваний аліас, який не ламається при deprecation).

> **Nginx:** файл журналу захищено `.htaccess` (Apache/LiteSpeed). Для nginx додайте в конфіг:
> `location ~* /ai-pdf-generator/.*\.log$ { deny all; }`

## Складання release-архіву (`build.sh`)

Готовий до завантаження через **Плагіни → Додати новий** ZIP (з `vendor/`, без службових файлів) збирається одним скриптом:

```bash
./build.sh
```

Скрипт:
1. запускає `composer install --no-dev --optimize-autoloader`;
2. копіює файли у тимчасовий staging, виключаючи `.git`, `.gitignore`, `build.sh`, `build/`, `composer.lock`, файли IDE/ОС та логи;
3. пакує все в теку з ім'ям slug — щоб WordPress розпакував архів у правильну `wp-content/plugins/ai-pdf-generator/`;
4. видає `build/ai-pdf-generator-<version>.zip` (версія береться із заголовка `Version:` головного файлу).

## Структура

```
ai-pdf-generator/
├── ai-pdf-generator.php        # Головний файл: константи, підключення, bootstrap
├── includes/
│   ├── triggers.php            # Каталог смарт-тригерів (label / condition / placeholders)
│   ├── fields.php              # editable_fields: нормалізація, санітизація, JSON-зберігання
│   ├── brand.php               # Брендинг: лого, колір, реквізити → плейсхолдери
│   ├── cpt-register.php        # CPT pdf_ai_template (прихований)
│   ├── template-editor.php     # Візуальний редактор + живе превю (замість сирого HTML)
│   ├── admin-page.php          # Playground (вкладки), налаштування, журнал подій
│   ├── ajax-handler.php        # generate / refine / save + виклики Gemini
│   ├── pdf-renderer.php        # Рендер у PDF через mPDF, підстановка плейсхолдерів
│   ├── trigger-dispatcher.php  # Слухає хуки активних плагінів → генерує PDF
│   ├── delivery.php            # Доставка: email-вкладення / download-link
│   ├── cron-cleanup.php        # Щоденне прибирання старих PDF
│   └── logger.php              # Audit log із ротацією
├── assets/
│   ├── admin.js                # Playground: draft / refine / save, медіа-аплоадери
│   ├── editor.js               # Редактор шаблону: поля + живе превю
│   └── frontend.js             # Кнопка завантаження PDF під формами (CF7 / Elementor)
├── composer.json               # Залежність: mpdf/mpdf ^8.2
├── build.sh                    # Складання release-архіву
└── README.md
```

## Розробка

Локальна синхронізація коду в тестову WordPress-інсталяцію:

```bash
./sync-local.sh
```

## Ліцензія

GPL-2.0-or-later.
