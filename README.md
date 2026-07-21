# AI PDF Generator

A WordPress plugin that generates PDF documents (invoices, tickets, certificates, badges, thank-you letters, and more) from HTML templates created via the **Google Gemini API**. Describe the document in plain language — the plugin generates a template, lets you fine-tune it visually, and automatically renders the finished PDF on plugin events (WooCommerce, Amelia, forms, etc.).

## Features

- **AI template generation (PDF Generator page).** A text prompt → Gemini returns an HTML skeleton plus structured `editable_fields`.
- **Draft mode + chat refinement.** A generated template is first shown as a draft (no post is created yet). "Refine" sends the current layout back to Gemini along with your instructions; "Save Template" finalizes it as a CPT.
- **Reference images.** Attach a design sample to your prompt (via the WordPress media library) — it's sent to Gemini as `inline_data`, and the AI reproduces its layout, colors, and style.
- **Visual editor (no code).** Instead of raw HTML — dynamic fields: WP Color Picker for colors, `input`/`textarea` for text. The live preview updates instantly. JSON is the source of truth.
- **Smart triggers (65 events).** A catalog of triggers keyed to real hook names (WooCommerce, EDD, Amelia, Bookly, TEC, 9 form plugins, LMS, memberships, donations, CRM, WP core). Only triggers for currently active plugins are shown; each carries its own contextual placeholders.
- **Smart placeholders.** `{{client_name}}`, `{{order_id}}`, `{{order_total}}`, `{{qr_code}}`, etc. — substituted with real event data at render time.
- **Branding.** Logo (media library), primary color, and company details live in Settings and are exposed as `{{logo_url}}`, `{{brand_color}}`, `{{company_name}}`, `{{company_address}}`, `{{company_email}}` — no hardcoding in templates.
- **Two generation modes.** Bring your own Gemini API key (free, direct calls), or use the managed **Cloud Service** — get a free trial (3 generations) or a paid license key with pooled credits, tiered plans, domain limits, and expiry, all surfaced as a live status dashboard in the License tab.
- **PDF rendering via mPDF.** Full UTF-8/Cyrillic support out of the box (DejaVu Sans), custom page sizes (A4, Letter, `800x400` px), table layouts, and inline styles.
- **Delivery.** `attach_to_email` (emailed attachment) or `download_link` (button on the WooCommerce thank-you page / under a CF7 or Elementor form / `[aipdf_download_button]` shortcode for Amelia redirect flows).
- **Garbage collection.** WP Cron deletes PDFs older than the configured retention period every day.
- **Audit log.** A rotating file logger (5 MB) — API, render, and delivery errors are visible in the admin.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Composer (to install mPDF)
- A Google Gemini API key ([Google AI Studio](https://aistudio.google.com/)) — or a Cloud Service license/trial key instead

## Installation

1. Copy the `ai-pdf-generator/` folder into `wp-content/plugins/`.
2. Install dependencies inside the plugin folder:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Activate the plugin under **Plugins → Installed Plugins**.
4. Open **AI PDF → Dashboard**, and follow the onboarding on the **Instructions** tab. In short:
   - **License tab** — get a free trial or enter your own Gemini API key. To use your own key permanently, you can instead define a constant in `wp-config.php` (it takes priority over the stored option):
     ```php
     define( 'AIPDF_GEMINI_API_KEY', 'your-key' );
     ```
   - **Settings tab** — pick a Gemini model and set the PDF retention period.
   - **AI PDF → PDF Generator** (its own top-level menu item) — write a prompt and generate your first template.
5. Change the **Gemini model** if needed (default: `gemini-flash-latest`, a self-updating alias that won't break on deprecation).

> **Nginx:** the log file is protected via `.htaccess` (Apache/LiteSpeed). For nginx, add to your config:
> `location ~* /ai-pdf-generator/.*\.log$ { deny all; }`

## Building a release archive (`build.sh`)

A ZIP ready to upload via **Plugins → Add New** (with `vendor/`, without dev files) is built with one script:

```bash
./build.sh
```

The script:
1. runs `composer install --no-dev --optimize-autoloader`;
2. copies files into a temporary staging directory, excluding `.git`, `.gitignore`, `build.sh`, `build/`, `composer.lock`, IDE/OS files, and logs;
3. packages everything into a folder named after the slug — so WordPress extracts the archive into the correct `wp-content/plugins/ai-pdf-generator/`;
4. outputs `build/ai-pdf-generator-<version>.zip` (the version is read from the main file's `Version:` header).

## Structure

```
ai-pdf-generator/
├── ai-pdf-generator.php        # Main file: constants, includes, bootstrap
├── includes/
│   ├── triggers.php            # Smart trigger catalog (label / condition / placeholders)
│   ├── fields.php              # editable_fields: normalization, sanitization, JSON storage
│   ├── brand.php               # Branding: logo, color, company details → placeholders
│   ├── cpt-register.php        # pdf_ai_template CPT (hidden)
│   ├── template-editor.php     # Visual editor + live preview (instead of raw HTML)
│   ├── admin-page.php          # Dashboard (Instructions/Settings/License/Event Log) + PDF Generator page
│   ├── ajax-handler.php        # generate / refine / save + Gemini and cloud-licensing calls
│   ├── pdf-renderer.php        # PDF rendering via mPDF, placeholder substitution
│   ├── trigger-dispatcher.php  # Listens to active plugins' hooks → generates PDFs
│   ├── delivery.php            # Delivery: email attachment / download link
│   ├── cron-cleanup.php        # Daily cleanup of old PDFs
│   └── logger.php              # Audit log with rotation
├── assets/
│   ├── admin.js                # Dashboard + PDF Generator: draft / refine / save, license UI, media uploaders
│   ├── editor.js               # Template editor: fields + live preview
│   └── frontend.js             # PDF download button under forms (CF7 / Elementor)
├── composer.json               # Dependency: mpdf/mpdf ^8.2
├── build.sh                    # Release archive builder
└── README.md
```

## Development

Sync code locally into a test WordPress install:

```bash
./sync-local.sh
```

## License

GPL-2.0-or-later.
