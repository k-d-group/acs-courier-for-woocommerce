# ACS Courier for WooCommerce — Design

**Date:** 2026-09-02
**Type:** Open-source WordPress plugin, distributed via the WordPress.org Plugin Directory
**Licence:** GPL-2.0-or-later
**Markets:** Greece (GR) and Cyprus (CY)
**Supports:** WordPress 6.0+, WooCommerce 8.0+, PHP 8.0–8.4, HPOS and legacy order storage

---

## 1. Purpose

A production-grade WooCommerce integration for ACS Courier, usable by any store in Greece or
Cyprus. Staff create vouchers, print labels, issue the pickup list and track shipments from
wp-admin; customers pick home delivery or an ACS Smartpoint locker at checkout and follow the
parcel afterwards.

This is a general-purpose plugin, not a bespoke integration. **No store-specific assumptions,
no hardcoded credentials, no bundled merchant data.**

**In scope:** voucher creation, label printing (thermal + laser), pickup list workflow, tracking
sync, customer tracking display, pickup-point selection, COD, rate calculation, GR + CY.

**Out of scope (v1):** countries other than GR/CY (the API does not support voucher creation
elsewhere), multi-warehouse, returns/RVO automation, ACS Smart Point *sending* (drop-off).

---

## 2. WordPress.org compliance

Directory rules are design constraints, not packaging afterthoughts.

| Rule | How the design satisfies it |
|---|---|
| GPLv2-or-later compatible | `GPL-2.0-or-later`, `LICENSE` in repo, header in every file. Any bundled library must be GPL-compatible; minified assets ship with source. |
| **No artificial restrictions** — no license gates, paywalls, trials, quotas | Every feature in the codebase is available to every user, always. There is no "pro" tier, no feature flag tied to a key, no upsell nag. **This is why COD is in v1** rather than held back. |
| No external links on the public site without consent | No "powered by" output. Nothing is rendered on the storefront that the merchant did not configure. |
| Must use the assigned SVN repository | Git is the working repo; a `deploy` workflow syncs tagged releases to WordPress.org SVN. |
| Not a spammer, no abuse | No telemetry, no phone-home, no email collection, no admin nags beyond genuine configuration errors. |
| External service disclosure | `readme.txt` must state plainly that the plugin transmits order data to ACS Courier's API, link ACS's terms and privacy policy, and name exactly what is sent. This is a hard directory requirement for any plugin calling a third party. |

**Naming.** Display name **"ACS Courier for WooCommerce"**, slug `acs-courier-for-woocommerce`,
text domain identical to the slug. The `<Service> for WooCommerce` form is the pattern the
WooCommerce trademark policy accepts; `WooCommerce ACS` would not be.

> **Open question for the client:** "ACS Courier" is ACS's trademark. Descriptive use in a
> compatibility name is normally defensible, but before publishing I'd get written acknowledgement
> from ACS. This is a legal call, not a technical one, and it is cheap to ask now and expensive to
> unwind after publication.

**Privacy.** The plugin transmits customer name, address, phone, and email to ACS to create a
shipment. It registers a GDPR data exporter and eraser so merchants can honour subject requests,
and documents retention in `readme.txt`.

---

## 3. Constraints verified against the live API

Probed against a real ACS account, not assumed from the PDF.

| Finding | Consequence |
|---|---|
| Business errors return **HTTP 200** with `ACSExecution_HasError: false`, real message in `ACSValueOutput[0].Error_Message` | The client checks **both** channels. The single most likely cause of silent failure. |
| Envelope key misspelled `ACSOutputResponce`; fields `Cod_Ammount`, `Insurance_Ammount` | Isolated in one `FieldMap`; never leaks past the client layer. |
| `ACS_Price_Calculation` **works for Greece** (verified: €5.48 base / €5.80 incl. VAT) but **not Cyprus** | Strategy pattern: live API for GR, local rate table for CY. |
| **GR: 156 stores + 1,514 lockers. CY: 33 stores + 73 lockers.** ~1,590 pickup points | Rules out a plain dropdown. Drives indexed storage and geo pre-filtering (§6). |
| `Content_Type_ID` mandatory when destination is CY | Hard pre-flight validation; omission causes Larnaca customs fines. |
| Rate limit 10 concurrent calls/sec → **406** | Client-side throttle with backoff. |
| Vouchers must be printed before the pickup list is issued | Workflow guardrail. |
| An issued pickup list makes its vouchers permanently undeletable | Explicit confirmation before issuing. |
| ZIP is 5-digit GR, 4-digit CY | Country-aware validation. |
| Address validation requires Greek characters | Warn on Latin input rather than failing opaquely. |
| Weight 0.5–999 kg, max 99 pieces, volumetric = L×W×H/5000 | Clamp and warn at mapping time. |
| Locker delivery rejects `Item_Quantity > 1`; COD to a locker requires recipient email | Validated at checkout, while the customer can still act. |

---

## 4. Architecture

Four layers, dependencies pointing downward only.

```
┌──────────────────────────────────────────────────────────────┐
│ 4. WordPress / WooCommerce surface                           │
│    ShippingMethod · OrderMetaBox · BulkActions               │
│    PickupListScreen · LockerSelector · Emails · MyAccount    │
└────────────────────────────┬─────────────────────────────────┘
┌────────────────────────────▼─────────────────────────────────┐
│ 3. Services (WP-aware orchestration)                         │
│    ShipmentService · TrackingService · PickupListService     │
│    PickupPointRepository · RateResolver                      │
│      ├── GreeceRateStrategy  → live ACS_Price_Calculation    │
│      └── CyprusRateStrategy  → local weight-banded table     │
└────────────────────────────┬─────────────────────────────────┘
┌────────────────────────────▼─────────────────────────────────┐
│ 2. Domain (pure PHP value objects)                           │
│    Shipment · Voucher · PickupPoint · TrackingEvent          │
│    Country · Money · Weight · OrderMapper · FieldMap         │
└────────────────────────────┬─────────────────────────────────┘
┌────────────────────────────▼─────────────────────────────────┐
│ 1. AcsClient — ZERO WordPress dependencies                   │
│    envelope · dual error check · Throttle · Transport        │
└──────────────────────────────────────────────────────────────┘
```

Layer 1 is testable with plain PHPUnit against recorded fixtures — no WP bootstrap, tests in
milliseconds, every quirk in §3 covered by a regression test. `Transport` is the only seam:
`WpHttpTransport` (wraps `wp_remote_post`) in production, `ArrayTransport` replaying fixtures in
tests.

### Layout

```
acs-courier-for-woocommerce/
├── acs-courier-for-woocommerce.php   # bootstrap, HPOS declaration, requirement checks
├── uninstall.php
├── readme.txt · README.md · LICENSE · CHANGELOG.md · CONTRIBUTING.md · SECURITY.md
├── src/
│   ├── Api/          AcsClient · Transport · WpHttpTransport · Throttle · AcsException
│   ├── Domain/       Shipment · Voucher · PickupPoint · TrackingEvent · Country · Weight
│   ├── Mapping/      OrderMapper · FieldMap · AddressSplitter
│   ├── Service/      ShipmentService · TrackingService · PickupListService
│   │                 PickupPointRepository · RateResolver · Rates/{Greece,Cyprus}Strategy
│   ├── Admin/        SettingsPage · OrderMetaBox · BulkActions · PickupListScreen · Logs
│   ├── Frontend/     LockerSelector · TrackingDisplay
│   ├── Integration/  ShippingMethod · CodGateway · Emails · Scheduler · Privacy
│   └── Support/      Installer · Migrations · Requirements
├── assets/           css · js (source + built)
├── languages/        .pot + el_GR
└── tests/            Unit · Integration · fixtures
```

---

## 5. Data flow

```
Order paid
   │
   ▼ ShipmentService::create   (idempotency lock + existing-voucher check)
   ├─ OrderMapper → ACS fields
   │    · weight: sum line items, clamp to 0.5 kg floor, volumetric if greater
   │    · address: street / number / region split (ACS rejects region inside address)
   │    · destination CY → Content_Type_ID required
   │    · locker chosen → Acs_Station_Destination + Branch + product REC
   │    · COD enabled → Cod_Ammount + Cod_Payment_Way + product COD
   ├─ validate locally — no API call on data ACS will reject
   └─ ACS_Create_Voucher → Voucher_No on order meta + order note
   │
   ▼ ACS_Print_Voucher_V2 → byte array → PDF (thermal / laser A4, up to 10 per call)
   │   marks label printed — prerequisite for the next step
   │
   ▼ ACS_Issue_Pickup_List   ← MANDATORY; without it barcodes are not recognised
   │   blocked unless every selected voucher is printed
   │   confirmation: vouchers become undeletable
   │
   ▼ Tracking — Action Scheduler, backing off by shipment age
       status 4 delivered · 6 returning · 7 returned
       non-delivery codes (AD1/AP1/LS3/…) → human-readable order note
```

**Idempotency.** Voucher creation is guarded by an order-meta lock plus an existing-`Voucher_No`
check. A double-click, retried job, or duplicated scheduled action must never create two
vouchers — that is a second real parcel and a real charge.

---

## 6. Pickup points at scale

~1,590 points across both countries. This is the part a naive implementation gets wrong.

**Storage.** A dedicated table, not options or transients — 1,590 serialised rows in an autoloaded
option would be a performance regression on every page load. Indexed on `(country, kind, postcode)`
plus lat/lng. Refreshed daily by Action Scheduler, with a manual refresh in settings.

**Selection.** Checkout never calls ACS.
1. Customer's postcode narrows candidates via the indexed `postcode` column.
2. Remaining candidates sorted by haversine distance from the postcode centroid.
3. Rendered as a searchable, paginated list — name, address, opening hours, distance.
4. Choice stored in session, then order meta, then echoed on the order, admin screen and emails.

**No map in v1.** It needs a tile provider and API key for marginal gain over a distance-sorted
list, and an embedded third-party map raises an external-request disclosure obligation. The schema
carries lat/lng so a map is additive later.

**Validation at checkout, not at voucher time:** locker delivery rejects multi-piece shipments,
and COD to a locker requires an email — surfaced while the customer can still change something.

---

## 7. Rates

`RateResolver` picks a strategy by destination country.

- **Greece — `GreeceRateStrategy`.** Live `ACS_Price_Calculation`, cached per
  (origin, destination, weight band, products) for 24h. Falls back to the local table if the API
  is unreachable, so checkout never breaks because ACS is down.
- **Cyprus — `CyprusRateStrategy`.** Weight-banded local table, editable in settings, separate
  home and locker columns (locker cheaper, which is the incentive to use one). Configurable
  free-shipping threshold and volumetric toggle.

Shipped defaults are **empty placeholders, not invented prices** — real rates depend on each
merchant's ACS contract.

---

## 8. Extensibility

A directory plugin is a platform for other developers. A documented, stable hook surface:

- `acs_wc_before_create_voucher` / `acs_wc_after_create_voucher`
- `acs_wc_voucher_payload` — filter the outgoing field array
- `acs_wc_rate` — filter a calculated rate
- `acs_wc_pickup_points` — filter/extend the candidate list
- `acs_wc_tracking_updated` — react to a status change
- `acs_wc_should_auto_create_voucher` — control automation

Public service classes resolve through a small container so integrators can substitute
implementations. Every hook is documented in `README.md` with signature and an example.

---

## 9. Error handling

1. **Transport** (timeout, DNS, 5xx) — exponential backoff, max 3 attempts, then surface and
   leave the order unchanged. Never partially mutate.
2. **Auth / rate limit** — 403 never retries (it cannot succeed); 406 backs off and requeues.
3. **Business** (200 + error in either channel) — `AcsException` carrying ACS's message, written
   verbatim to an order note and shown as an admin notice. ACS messages are often Greek and are
   shown as-is rather than mistranslated.

Logging via `WC_Logger` (source `acs-courier`), visible under WooCommerce → Status → Logs,
with **credentials redacted**. Log level configurable; off by default.

---

## 10. Security

- Credentials stored in options, gated on `manage_woocommerce`, and **overridable by constants**
  (`ACS_WC_API_KEY`, …) so production secrets never need to live in the database.
- API key never rendered, never logged, masked in the settings field.
- Every admin action nonce-protected and capability-checked.
- Label PDFs stream through an authenticated handler, never a guessable uploads URL.
- All input sanitised, all output escaped, all SQL prepared — enforced by PHPCS/WPCS in CI.
- `uninstall.php` removes options, tables and scheduled actions on explicit uninstall only.

---

## 11. Testing

**Unit (no WordPress):** `AcsClient` against fixtures — dual-error case, misspelled envelope,
403, 406, malformed JSON, timeout. `OrderMapper` — weight clamping, address/region splitting,
CY content-type enforcement, locker fields, COD fields. Rate strategies — band boundaries,
volumetric crossover, GR API fallback.

**Integration (WP + WooCommerce):** full create → print → issue → track cycle against a real ACS
account. Vouchers created in tests are deleted in teardown **before** any pickup list is issued,
since issuing makes deletion impossible.

**Compatibility matrix in CI:** PHP 8.0/8.1/8.2/8.3/8.4 × WooCommerce floor and latest ×
HPOS on/off. PHPCS (WordPress-Extra + WordPress-Docs) and PHPStan level 6.

**Manual:** checkout locker selection on mobile and desktop; thermal and A4 label output.

---

## 12. Build phases

| # | Phase | Hours | Done when |
|---|---|---|---|
| 1 | `AcsClient`, throttle, fixtures | 4 | Every §3 quirk has a passing regression test |
| 2 | Domain, `OrderMapper`, `FieldMap` | 4 | Order → valid payload, entirely offline |
| 3 | Plugin scaffold, requirements, settings, credentials | 4 | Activates cleanly; constants override |
| 4 | Voucher creation + idempotency | 5 | Real voucher on staging, deleted in teardown |
| 5 | Labels (thermal + laser + bulk) | 4 | Valid PDF from byte array |
| 6 | Pickup list + workflow guardrails | 4 | Cannot issue with unprinted vouchers |
| 7 | Pickup-point storage, sync, checkout selector | 8 | 1,590 points searchable without slowing checkout |
| 8 | Shipping method + both rate strategies | 6 | GR live pricing; CY table; API-down fallback |
| 9 | COD gateway + reconciliation | 4 | COD order → correct voucher fields |
| 10 | Tracking sync + customer display | 5 | Status transitions drive notes and order state |
| 11 | i18n (EL/EN), a11y, hooks documentation | 4 | `.pot` complete; hooks documented |
| 12 | WordPress.org packaging, CI matrix, PHPCS/PHPStan | 5 | Green matrix; `readme.txt` passes the validator |
| 13 | Hardening + end-to-end pass on a clean install | 4 | Full cycle on a fresh WP/WC install |

**Total: ~57–63 h** (≈ 7–8 working days).

The earlier 36–44 h figure was for a Cyprus-only, prepaid-only, single-site integration. Greece
support, COD, ~1,590 pickup points, the extensibility surface, the CI matrix and directory
compliance account for the difference.

---

## 13. Decisions and reasons

| Decision | Why |
|---|---|
| WP-free API client | ACS's contract is quirky; isolation makes every quirk regression-testable without a WP bootstrap. |
| Rate strategy per country | Verified: price calculation works for GR, not CY. One code path would be wrong for someone. |
| Pickup points in a table, not options | 1,590 rows autoloaded on every request would be a site-wide performance regression. |
| COD included in v1 | Directory rules forbid artificial feature restrictions, and COD is table stakes in GR/CY. |
| No map in v1 | Tile provider + key, plus an external-request disclosure, for marginal gain over a sorted list. |
| Action Scheduler over wp-cron | Ships with WooCommerce; observable, retryable, survives traffic gaps. |
| Declare HPOS compatible | Costs nothing now; avoids a migration wall for adopters. |
| Idempotency lock | A duplicate voucher is a real parcel and a real charge. |
| No telemetry, no upsell | Directory rules, and it is the right default. |
| GR API rate fallback to local table | Checkout must not break because a third party is down. |
