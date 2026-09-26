# ACS Courier for WooCommerce

[![CI](https://github.com/Hikhakk/acs-courier-for-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/Hikhakk/acs-courier-for-woocommerce/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

Create [ACS Courier](https://www.acscourier.net/) vouchers and track shipments from
WooCommerce. Supports **Greece** and **Cyprus**.

Free and unrestricted — no paid tier, no licence key, no usage limits, no telemetry.

> Not affiliated with or endorsed by ACS Courier. You need your own ACS business account.

## Requirements

| | |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 8.0+ |
| PHP | 8.0 – 8.4 |
| Order storage | HPOS **and** legacy both supported |

## Installation

Install through the Plugins screen, then set your ACS credentials under
**WooCommerce → Settings → Shipping → ACS Courier**.

For production, keep secrets out of the database by defining them in `wp-config.php` —
these take precedence over the settings screen and the fields become read-only:

```php
define( 'ACS_WC_COMPANY_ID',       '...' );
define( 'ACS_WC_COMPANY_PASSWORD', '...' );
define( 'ACS_WC_USER_ID',          '...' );
define( 'ACS_WC_USER_PASSWORD',    '...' );
define( 'ACS_WC_API_KEY',          '...' );
```

## Architecture

Four layers, dependencies pointing downward only:

```
WordPress surface   Settings · OrderMetaBox · WooOrderReader
        ↓
Services            ShipmentService · OrderLock
        ↓
Domain              Shipment · Country · Weight · FieldMap · OrderMapper
        ↓
AcsClient           zero WordPress dependencies
```

`AcsClient` deliberately has **no WordPress dependency**, so the whole of ACS's contract is
regression-tested against recorded fixtures in milliseconds, with no WordPress bootstrap.
`Transport` is the only seam: `WpHttpTransport` in production, `ArrayTransport` in tests.

### Why that matters

ACS's API has behaviours that will bite any naive client, each of which has a test:

- **Business errors return HTTP 200** with `ACSExecution_HasError: false`, hiding the real
  message in `ACSValueOutput[0].Error_Message`. A client that trusts the status code or the
  documented flag reports success on failure.
- The response envelope is misspelled `ACSOutputResponce`, and fields are `Cod_Ammount` and
  `Insurance_Ammount`. These live in exactly one class, `FieldMap`.
- Rate limiting is HTTP **406**, which is retryable; **403** never is.
- `ACS_Price_Calculation` works for Greece but **not** Cyprus.
- A pickup list, once issued, makes its vouchers permanently undeletable.
- Printed documents are nested inside `ACSValueOutput` as `{Voucher_No, PDFData}`, not at
  the top level as the documentation implies.
- A Smartpoint destination must carry **no** product code, while an ACS store requires
  `REC`. Mixing them is rejected.
- Tracking returns "no shipment found" until the pickup list is issued. That is normal.

## Development

```bash
composer install
composer test      # unit tests, no WordPress needed
composer lint      # PHPCS: WordPress-Extra + WordPress-Docs + PHP 8.0 floor
composer analyse   # PHPStan level 6 with WordPress/WooCommerce stubs
composer check     # all three
```

Integration tests hit the real ACS API and skip themselves unless credentials are present:

```bash
cp .env.example .env   # fill in your ACS staging credentials
composer test:integration
```

Vouchers created by integration tests are deleted in `tearDown`, before any pickup list is
issued — after that, deletion is impossible.

## Roadmap

- [x] Voucher creation from an order
- [x] Label printing (thermal and A4 laser)
- [x] Pickup list workflow
- [x] Tracking sync and non-delivery reasons
- [x] Pickup point model and distance search (1,620 live points verified)
- [x] Checkout locker selector
- [x] Rate resolution: live Greek pricing, Cypriot rate table, fallback when ACS is down
- [x] WooCommerce shipping method
- [x] Cash on delivery
- [ ] Map view for pickup point selection

## Need help?

The plugin is free and so is the code. If you would rather have it installed, configured or adapted to how you ship (other couriers, your own label layout, a different checkout), the team that wrote it can do that for you: [kdvassiliougroup.com](https://kdvassiliougroup.com/contact/?from=github-acs&service=integrations).

## Contributing

Issues and pull requests welcome. Run `composer check` before opening a PR.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
