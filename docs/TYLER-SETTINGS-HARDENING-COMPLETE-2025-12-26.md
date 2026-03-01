/home/u3007-tenkoaygp3je/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/docs/YUKIA-SETTINGS-HARDENING-COMPLETE-2025-12-26.md
# PostPress AI — Yukia Settings Hardening Complete (2025-12-26)

Working Date: 2025-12-26
Timezone: America/Chicago

## Scope (What this doc covers)
This document locks the completion state for:
- WP Admin Hardening + Licensing UI (Settings screen)
- Settings screen branding polish + UX guardrails
- CSS isolation rules (Settings-only)
- Server-side-only WP → Django communication rules

## Non-Negotiable Rules (LOCKED)
1) Django is authoritative. WordPress never decides license validity.
2) WP → server-side only → Django. No browser → Django calls.
3) One file at a time. Always return FULL file content.
4) CSS isolation is absolute:
   - Settings → admin-settings.css ONLY
   - Composer → admin-composer.css ONLY (DO NOT TOUCH)
   - Testbed → loads assets only if enabled
5) Settings screen = CSS-only. No JS stack.
6) Preserve aggressive asset purge (never purge other plugins).
7) Response format (for ongoing work): date/time → % → code drop → tests → git commands
8) Update progress/date markers to the current working date.

## What shipped (DONE)
### Settings architecture (WP)
- Server URL is hidden from UI; supported internally via constant/option.
- Connection Key removed from UI; legacy still supported internally if present.
- Single user-facing input: License Key only.

### Auth fallback logic (WP → Django)
Auth key resolution order:
1) PPA_SHARED_KEY constant (if defined)
2) Legacy ppa_shared_key option/filter (if set)
3) License Key as X-PPA-Key fallback

### License actions (server-side admin-post)
- Check / Activate / Deactivate call Django /license/* server-side.
- Test Connection calls Django /version/ and /health/ server-side.

### UX guardrails (Settings)
- Activate disabled until License Key is saved.
- Status badge shown (PHP-only, no JS):
  - Active on this site / Not active / Unknown
- Convenience UI marker:
  - After successful Activate, store ppa_license_active_site = home_url('/');
  - Clear it after successful Deactivate.
  - NOTE: This is NOT enforcement. Django remains authoritative.

### CSS (Settings only)
- Settings page styling is strictly scoped to:
  body.postpress-ai_page_postpress-ai-settings
- Fixed .button vs .button-primary specificity issues.
- Right-side License Actions:
  - calm layout
  - stacked full-width buttons
  - improved spacing
  - disabled Activate looks intentional
- Debug box reduced and visually calmer.
- Final micro-polish: hover/focus transitions, calm card hover, status badge styling.

## Backlog rollup (Status)
All items below are considered DONE for this epic:
- Composer guardrails ✅
- Hide Publish ✅
- Simplify helper copy ✅
- Settings branding parity ✅
- Hide Testbed menu (hide only) ✅
- Activation redirect to Settings ✅
- Modular admin CSS per screen ✅
- Elegant spinner ✅

## Verification checklist (Run this before tagging)
### WP-Admin UI checks
1) PostPress AI → Settings
   - License actions are calm/stacked
   - Activate disabled until key saved
   - Badge renders correctly
   - After Activate: “Active on this site” marker shows
   - After Deactivate: marker clears
2) Composer screen untouched:
   - Buttons: Generate Preview + Save Draft (Store)
   - Publish button not visible
3) Testbed hidden by default

### Git checks (repo hygiene)
- git status == clean
- latest commits present
- tag exists and pushed

## Tag name (recommended)
yukia-settings-hardening-complete-2025-12-26
