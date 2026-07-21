# Reusable prompt: WordPress plugin admin structure

Paste this into a new session (fill in the `{{PLACEHOLDERS}}`) to bootstrap a
plugin admin area with the same architecture as AI PDF Generator: a tabbed
dashboard, a standalone "workhorse" page, an onboarding tab, and a
freemium license/account system.

---

## Prompt

We're building a WordPress plugin called **{{PLUGIN_NAME}}** (slug
`{{plugin-slug}}`, main class `{{PLUGIN}}_Plugin`). It does:
{{ONE_SENTENCE_DESCRIPTION — e.g. "generates PDF documents from AI-written
HTML templates"}}.

Set up the admin area with this exact structure:

### 1. Menu layout

- One top-level menu page (`add_menu_page`, icon `{{dashicon}}`), callback
  `render_page()` — this becomes the "Dashboard" page.
- An **explicit first submenu item** with the *same slug* as the parent menu
  (label "Dashboard"), calling the same `render_page()`. This is required —
  without it, any CPT registered with `show_in_menu => {{ADMIN_SLUG}}` pushes
  the dashboard out of the submenu entirely.
- A **second submenu item**, own slug (e.g. `{{plugin-slug}}-generator`),
  label "{{MAIN_FEATURE_NAME}}" (e.g. "PDF Generator"), callback
  `render_generator_page()`. This is the actual tool people use day to day —
  it gets its **own page**, not a tab, because it's opened far more often
  than settings/license and shouldn't be buried behind tab clicks.
- Capture the second submenu's hook suffix from `add_submenu_page()`'s
  return value into a private property (its hook name isn't a predictable
  string like the top-level page's `toplevel_page_{{slug}}`):
  ```php
  private string $generator_hook = '';
  // in register_menu():
  $this->generator_hook = (string) add_submenu_page( /* ... */ );
  ```
- In `enqueue_assets( $hook_suffix )`, only enqueue on the dashboard page
  (`'toplevel_page_' . ADMIN_SLUG === $hook_suffix`) OR the generator page
  (`$hook_suffix === $this->generator_hook`). Bail otherwise.
- Any CPT list this plugin manages attaches automatically via
  `'show_in_menu' => {{ADMIN_SLUG}}` in `register_post_type()`.

### 2. Dashboard page tabs (JS-driven, no page reload)

`render_page()` outputs one `<div class="wrap">` containing a
`nav-tab-wrapper` and one `<div class="{{prefix}}-tab">` per tab, all but
the first `style="display:none"`. Tabs, in this order:

1. **Instructions** (`#instructions`) — onboarding, active by default.
2. **Settings** (`#settings`) — plain `Settings API` form
   (`register_setting` + `settings_fields()` + `options.php`).
3. **License** (`#license`) — see section 4.
4. **Event Log** (`#logs`) — tail of a rotating file logger, "Clear Log"
   button via `admin-post.php`.

JS tab switching (`assets/admin.js`, wrapped in `(function($){ $(function(){
...` }())`):

```js
function activateTab( name ) {
    if ( ! $( '#{{prefix}}-tab-' + name ).length ) {
        name = 'instructions'; // fallback == the onboarding tab, not the first tab blindly
    }
    $( '#{{prefix}}-tabs .nav-tab' ).removeClass( 'nav-tab-active' )
        .filter( '[data-tab="' + name + '"]' ).addClass( 'nav-tab-active' );
    $( '.{{prefix}}-tab' ).hide();
    $( '#{{prefix}}-tab-' + name ).show();
}
$( '#{{prefix}}-tabs' ).on( 'click', '.nav-tab', function ( e ) {
    e.preventDefault();
    activateTab( $( this ).data( 'tab' ) );
    window.history.replaceState( null, '', '#' + name ); // survives F5
} );
// Initial tab: URL hash -> after settings save -> after log clear -> instructions.
```

Any in-page link that should switch tabs (e.g. an onboarding step's "go to
Settings" link, a promo banner's "View Pricing" link) uses `href="#tabname"`
plus a shared delegated click handler that reads the target from `href` and
calls `activateTab()` — don't hardcode a separate handler per link.

A form on a non-active-by-default tab (e.g. Settings, License) needs an
explicit `<input type="hidden" name="_wp_http_referer" value="...#tabname">`
so that after `options.php` redirects back with `?settings-updated=true`,
the URL still carries the right hash and the JS reopens the correct tab.

### 3. Instructions tab (onboarding)

A numbered-steps card (Activation → Configuration → Generation, or whatever
your setup flow is) plus a tips box below it:

```php
<div class="{{prefix}}-onboard-card">
    <p class="{{prefix}}-onboard-title">Welcome to {{PLUGIN_NAME}}! 👋</p>
    <ol class="{{prefix}}-onboard-steps"><!-- counter-based numbered circles via CSS --></ol>
</div>
<div class="{{prefix}}-onboard-tips"><!-- plain styled div, see the notice-class warning below --></div>
```

**Gotcha to avoid:** don't use WordPress's `notice`/`notice-info` classes on
anything meant to stay inside a tab. WP core JS auto-relocates *any*
`.notice:not(.inline)` element to right after the page's first heading,
regardless of DOM nesting — it will yank your tips box out of the tab and
render it above the tab bar. Use a custom class with matching styles
instead (light background + colored left border reproduces the "notice"
look without the relocation behavior).

### 4. License / Account tab (freemium model)

- **Generation mode toggle**: two radios, "Own API Key" vs "Cloud Service",
  saved via `register_setting` with a sanitize callback that only accepts
  the two known values (fallback to the free/direct mode on anything else).
  JS shows/hides the matching key-input row on `change` (tag each `<tr>`
  with an id, toggle `.show()`/`.hide()` based on which radio is checked —
  apply once on load too, not just on change).
- **Trial activation button**: visible only when no license key is saved.
  AJAX call to your licensing backend; on success, **don't reload the
  page** — drop the returned token into the key `<input>` via `.val()`,
  check the Cloud Service radio and `.trigger('change')`, fade out the
  trial button, and immediately call the status-fetch function so the
  dashboard appears without a round trip.
- **Status dashboard**: `<div id="{{prefix}}-license-status-card">` placed
  **above** the settings form, not inside it. Fetches lazily — only when
  the License tab is actually opened (hook into `activateTab()`), not on
  every page load, and guard against duplicate concurrent requests with a
  boolean flag reset in `.always()`.
  - On success: render a white card, rounded corners, layered box-shadow,
    a small pulsing colored dot + "Active Plan: X" header, and a
    `grid-template-columns: repeat(auto-fit, minmax(130px, 1fr))` tile grid
    for numeric stats (credits, seats/domains, expiry) with big bold
    values. Then **collapse** the key-management form
    (`$('#{{prefix}}-settings-wrap').removeAttr('open')` if it's a
    `<details>` element) so the technical fields don't compete with the
    dashboard.
  - On error/no license: render an error notice and make sure the
    key-management panel is expanded (`.attr('open', '')`) so the user can
    act.
  - Render every backend-controlled string via `.text()` /
    `document.createTextNode()`, never `.html()` — the response body is
    attacker-adjacent (comes from an external API over HTTP).
- **Pricing table**: 2–4 flexbox cards below the form, shown only while no
  license key is saved. Plain CSS card grid, no JS needed.
- **Promo banner**: `<div id="{{prefix}}-promo-banner" class="notice
  notice-warning">` right after `<h1>` (so WP's relocation script is a
  no-op here — it's already in the right place), shown site-wide across
  every tab/page when no credentials are configured, linking to `#license`.

### 5. AJAX handler conventions

- One handler class, `wp_ajax_{{action}}` registered per action —
  **no** `wp_ajax_nopriv_*` unless the action is genuinely meant for
  logged-out users.
- Every handler starts with `check_ajax_referer( '{{nonce_action}}',
  'nonce' )` then `current_user_can( 'manage_options' )`.
- External API calls: `wp_remote_get`/`wp_remote_post`, check
  `is_wp_error()` first, then accept **all 2xx codes that mean success**
  for that specific backend (e.g. both 200 and 201 for a "resource
  created" endpoint) — don't hardcode a single status code if the API can
  legitimately return more than one on success.
- Log the *raw response body* (not just a static message) when a response
  doesn't match your expected shape — external API contracts change, and a
  static "unexpected response" log line gives you nothing to debug with
  later.
- Field names from an external API can rename between versions
  (`plainTextToken` → `license_key` is a real example this codebase hit) —
  when reading a value the backend controls, check the current field name
  first but keep a fallback to the older name for one version, and always
  log which shape you got when neither matches.

### 6. JS structure conventions

- One `assets/admin.js`, one `(function($){ 'use strict'; $(function(){
  ...` }())` wrapper for the whole file.
- Each feature area is either a named function (if other code needs to
  call it, like `fetchLicenseStatus()`) or a self-invoking `(function(){
  ...}());` block (if it's fire-and-forget on page load).
- **Every** block that targets specific markup starts with `if ( !
  $thing.length ) return;` — this is what lets the *same* `admin.js` file
  safely run on multiple different admin pages (dashboard tabs +
  standalone generator page) without needing per-page conditional
  enqueuing logic beyond the hook-suffix check in step 1.

### 7. Verification workflow (do this before calling anything done)

1. `php -l` every changed PHP file, `node --check` every changed JS file.
2. For AJAX handlers touching external services: mock the HTTP layer via
   WordPress's `pre_http_request` filter (both a success shape and an
   error shape) rather than hitting the real backend — never spend a real
   trial/credit during automated testing.
3. Live-verify in a browser against the real local WP install: generate
   fresh auth cookies via `wp_generate_auth_cookie()` if there's no stored
   admin password, screenshot the actual rendered page, click through the
   real flow (tab switches, trial button, license status render).
4. If you need to test a real external call once (e.g. confirm the actual
   field names in a live API response), do it as a **safe, idempotent**
   read against state that's already in a "used" or otherwise
   non-consuming condition — don't burn a real trial/quota to debug.

---

## Notes for the person filling this in

- Replace every `{{PLACEHOLDER}}` before sending.
- If the plugin doesn't have a freemium/license model, skip section 4
  entirely and just keep Instructions/Settings/Event Log tabs.
- If there's no single "main feature" that deserves its own page, skip the
  second submenu in section 1 and put everything in tabs instead — the
  separate-page pattern is specifically for the one screen people open the
  most.
