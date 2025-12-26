/home/u3007-tenkoaygp3je/www/techwithwayne.com/public_html/wp-content/plugins/postpress-ai/docs/STRIPE-FULFILLMENT-PLAN-2025-12-26.md
# PostPress AI — Stripe Fulfillment Plan (2025-12-26)

Working Date: 2025-12-26
Timezone: America/Chicago

## Purpose
Wire Stripe in as a **fulfillment layer** only:
- Stripe does NOT change licensing truth.
- Django stays authoritative.
- WordPress stays a “license consumer” (enter key → activate/verify/deactivate server-side).

## Current state (LOCKED / DONE)
- WP Settings hardening complete (server-side calls only).
- Django licensing endpoints exist: /license/verify/, /license/activate/, /license/deactivate/
- CSS isolation rules are locked.
- No JS stack on Settings.

## What Stripe adds (simple)
Stripe’s job is just:
1) Payment succeeds
2) Django receives webhook
3) Django issues a license key
4) Django emails the license key + stores purchaser info

WordPress does not need to know Stripe exists.

## Stripe flow (high-level)
### A) Checkout
- Customer pays on Stripe Checkout.
- Stripe attaches metadata:
  - purchaser_email
  - product_tier (ex: yukia_early_access)
  - (optional) site_url captured on checkout form later

### B) Webhook → Django (authoritative)
- Stripe sends `checkout.session.completed` to Django webhook endpoint.
- Django verifies Stripe signature (required).
- Django creates/updates:
  - Customer record (email)
  - Order record (stripe_session_id, stripe_customer_id, payment_status)
  - License record (license_key, tier, status)

### C) Fulfillment
- Django generates license key (secure random).
- Django emails:
  - “Here’s your PostPress AI License Key”
  - simple steps: paste into WP Settings → Save → Activate
- (Optional later) show key on success page as well.

## Minimum data we store in Django
- purchaser_email
- stripe_customer_id (if available)
- stripe_session_id
- stripe_payment_intent (if available)
- amount paid + currency
- product_tier
- created_at
- license_key
- license_status (active/disabled/refunded)

## Refunds / chargebacks (later, optional but clean)
Webhook events:
- `charge.refunded` or `refund.updated` (varies)
- Action:
  - mark license as disabled (Django-side)
  - (optional) deactivate all activations for that key

NOTE: This is optional for v1. We can ship without auto-refund handling.

## Lock rules (still apply)
- Django is authoritative.
- WordPress never decides validity.
- WP → server-side only → Django (no browser calls).
- One file at a time, full file returns.

## Implementation order (Django-first)
1) Add Django webhook endpoint + Stripe signature verification
2) Add minimal models (Order, License, Customer) OR reuse existing license storage if already present
3) Add key generator + email sender
4) Add Stripe Checkout session creator endpoint (optional if you use Stripe Payment Links initially)

## “Stripe-ready” verification checklist
- [ ] Stripe webhook reaches Django and verifies signature
- [ ] Django creates license key on paid event
- [ ] Email sends with license key
- [ ] License key can be entered in WP Settings and activated (existing flow)
- [ ] No WP code changes required for Stripe v1
