# Reusable prompt: AI chat/generator UI pattern

Paste this into a new session (fill in the `{{PLACEHOLDERS}}`) to bootstrap
a chat-driven AI generator screen with the same architecture as AI PDF
Generator's Playground: one AI entry point for both first-generation and
refinement, a draft-then-save two-phase flow, dynamic AI-defined fields, and
a live preview.

---

## Prompt

We're building a chat-style generator screen in **{{PLUGIN_NAME}}** where
the user describes {{WHAT_IS_BEING_GENERATED — e.g. "a PDF document
template"}} in plain language, an AI ({{AI_PROVIDER — e.g. Gemini}})
returns a structured result, and the user can refine it conversationally
before saving. Build it with this architecture:

### 1. Server: one AI entry point for generate AND refine

**Do not** create separate `handle_generate()` and `handle_refine()`
actions. Use a single `handle_chat()` (or equivalent) that branches
internally on whether a `current_layout` (or equivalently-named "current
state") was sent:

```php
public function handle_chat(): void {
    $api_key = $this->guard_and_key(); // nonce + capability + API key, or die

    $user_prompt    = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
    $current_json   = (string) wp_unslash( $_POST['current_layout'] ?? '' );
    $current_layout = $this->decode_client_layout( $current_json ); // null if empty/invalid

    $turn = array(
        'role' => 'user',
        'text' => $current_layout
            ? $this->build_refine_prompt( $current_layout, $user_prompt ) // hands the FULL current state back to the model + the requested change
            : $user_prompt,
    );
    // Optional: attach a reference image on ANY turn, not just the first —
    // if refine and generate diverge here, you'll get a real bug where a
    // reference image silently vanishes on the second message.
    if ( $image = $this->build_reference_image( $_POST['reference_id'] ?? 0 ) ) {
        $turn['image'] = $image;
    }

    $result = $this->ask_ai( $api_key, array( $turn ) );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $this->friendly_ai_error( $result ) ), 502 );
    }

    // No CPT/DB row created here — only a preview, until an explicit "Save".
    wp_send_json_success( $this->draft_payload( $result, $user_prompt ) );
}
```

**Why this matters:** the single biggest source of chat bugs is generate
and refine handling the same concern (reference images, validation,
sanitization) in two different code paths that quietly drift apart. One
entry point with an internal branch eliminates that class of bug entirely.

The refine-prompt builder should:
- serialize the current state back to the model verbatim (same schema it
  outputs), so the model can diff against it;
- explicitly instruct the model to change ONLY what was asked and leave
  everything else byte-identical;
- explicitly forbid changing structural fields (type/format/category —
  whatever your equivalent of `trigger_plugin`/`action_type`/`paper_size`
  is) unless the user asked to.

`decode_client_layout()` should treat *any* problem (empty string, invalid
JSON, fails your normal AI-response validation) as "no current state" —
i.e. silently start fresh — rather than a fatal error. The client is
untrusted input even though it's just echoing back what you sent it
earlier.

Return a friendly, generic message for AI-shape errors (empty response,
broken JSON, missing required field) instead of a raw exception — but pass
real HTTP/auth/rate-limit errors through as-is, since those are actionable
for the user (e.g. "check your API key").

### 2. Client-side state: the payload IS the memory, not a message list

Don't accumulate a growing array of chat messages and resend history. Keep
ONE state object holding the current structured result plus the exact JSON
string the server last returned:

```js
var chat = {
    layout:          null,  // parsed object, used for rendering
    layoutJson:      '',    // verbatim string from the server, sent back next turn
    basePrompt:      '',    // first message, reused as the title/label when saving
    busy:            false,
    savedAndCurrent: false  // true only right after a successful save with no changes since
};
```

Every AJAX call sends `current_layout: chat.layoutJson`. The chat log in
the UI is purely cosmetic (what the user sees) — it is never reconstructed
into the request.

**On error, touch nothing but the visible chat log.** Append an error
bubble; do not modify `chat.layout`, the preview, or the dynamic fields.
The user's last-known-good state must survive a failed request untouched.

**On success**, one function applies the new state everywhere it needs to
go — never scatter `chat.layout = ...` across multiple handlers:

```js
function applyLayout( d ) {
    chat.layout = { /* map every field from the response */ };
    chat.layoutJson = d.current_layout || JSON.stringify( chat.layout );
    chat.savedAndCurrent = false; // a fresh/refined layout is always unsaved
    renderPreview();
    renderFields();
    updateMeta();
    $saveBtn.show();
    $saved.hide();
}
```

### 3. Two-phase flow: draft in memory, explicit Save persists it

Generating/refining never writes to the database — it only returns a
preview payload. A separate `handle_save()` action re-validates and
re-sanitizes the client-submitted draft **exactly as if it were a fresh AI
response** (never trust that client-side state wasn't tampered with
between generate and save) and creates the actual post/row.

UI consequence: an "unsaved" badge tracks whether the currently-displayed
draft matches what's persisted:
- Set `savedAndCurrent = false` whenever the layout changes (new message,
  refinement, or a manual edit to a dynamic field).
- Set `savedAndCurrent = true` only in the Save button's success handler.
- A dedicated function toggles the badge's visibility purely off this one
  boolean — don't inline the show/hide logic in multiple places.

### 4. Dynamic fields: the AI defines the schema, you just render it

The AI's response includes a list of editable fields
(`editable_fields: [{key, type, label, value}, ...]`) alongside the main
content. Render form controls generically off `type` (text / textarea /
color, extend as needed) rather than hardcoding known field names — the
whole point is the AI decides what's editable per generation.

```js
function renderFields() {
    $fields.empty();
    if ( ! chat.layout || ! chat.layout.editable_fields.length ) return;
    chat.layout.editable_fields.forEach( function ( field, idx ) {
        // build the right input type, bind its change/input event to:
        //   updateFieldValue( idx, newValue )
    } );
}
function updateFieldValue( idx, value ) {
    chat.layout.editable_fields[ idx ].value = value;
    renderPreview();
    chat.savedAndCurrent = false;
    updateMeta();
}
```

### 5. Live preview: substitute, don't re-fetch

The preview re-renders client-side by substituting current field values
into the AI-returned template — it does not round-trip to the server on
every keystroke. Debounce free-text inputs (a `setTimeout`-based
scheduler is enough); color pickers and similar controlled inputs can
re-render immediately on `change`.

If the target output format can't be rendered natively in a browser
preview (e.g. a PDF-only native tag your renderer supports but HTML
doesn't), swap it for a close visual approximation in the preview only —
never in the data that actually gets saved/rendered server-side.

### 6. Reference input (image, file, etc.) is one-shot

If the generator accepts an attached reference (image via WP media
library, etc.), it's attached to the *next* message only. Clear it in the
AJAX `.always()` handler after every send — don't let it silently persist
across multiple turns unless the user re-attaches it.

### 7. Quick-start / example prompts

A `<select>` of ready-made example prompts that just fills the textarea on
`change` (no auto-send) lowers the blank-page problem. Pair it with
clickable "insert placeholder" chips (`<code data-{{token}}="...">`) using
a single delegated click handler that inserts at the cursor position —
don't bind one handler per chip.

### 8. Verification

1. `php -l` / `node --check` every changed file.
2. Live-test the full loop in a real browser: send a first message
   (generate), send a follow-up (refine — confirm it changes ONLY what was
   asked), attach + send a reference on a refine turn (confirm it isn't
   silently dropped), edit a dynamic field manually (confirm the unsaved
   badge reappears), click Save (confirm the badge clears and the
   persisted post/row matches exactly what was previewed), and trigger a
   deliberate error (e.g. malformed AI output) to confirm the previous
   state survives untouched.

---

## Notes for the person filling this in

- If there's no "refine" concept (one-shot generation only), you can drop
  the `current_layout` branch — but keep the draft/save split, since
  letting people preview before persisting is the real value here.
- If the AI's output has a fixed, known schema (no dynamic per-generation
  fields), skip section 4 and just bind a static form to static keys.
- The unsaved-state badge (section 3) is skippable for a generator that
  auto-saves every turn — only build it if there's a meaningful gap
  between "what's on screen" and "what's persisted."
