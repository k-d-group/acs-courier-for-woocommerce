# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.3] - 2026-10-07

### Fixed
- Voucher creation failed with `Invalid pickup date value.` for anything attempted between midnight
  and 03:00 store time. `Pickup_Date` was built with `gmdate()`, and Greece and Cyprus run UTC+2/+3,
  so in those hours ACS was handed yesterday's date. It now uses `current_time()`, the store's own
  date. The same correction applies to the price-calculation call.

## [0.4.2] - 2026-10-03

### Fixed
- Shipments to an **ACS store in Cyprus** were rejected with `Shipment product value REC is not
  valid.` The REC product exists in the Greek catalogue only; in Cyprus a store is addressed by
  station and branch alone, exactly like a Smartpoint locker. `Country::requiresStorePickupProduct()`
  now decides, and `OrderMapper` adds REC for Greece only. Confirmed against the live API: the
  printed voucher shows the chosen shop as its destination.
- The unit test covering this asserted REC on a **Cyprus** fixture, so it locked in the broken
  behaviour. Split into one test per country.

## [0.4.1] - 2026-09-02

### Fixed
Found by installing the plugin on a real WordPress 7.1 + WooCommerce 11 stack, which
the earlier releases had not been.

- Pickup points were never stored. ACS's *response* returns a numeric
  `ACS_SHOP_COUNTRY_ID` (1 = Greece) even though the request parameter of the same name
  takes `GR` or `CY`, so every row was filed under `'2'` and no query could find it. The
  requested country is now authoritative. 1,776 points now sync correctly.
- `sync()` counted rows it attempted rather than rows actually written, hiding the above.
- Action Scheduler was called during `plugins_loaded`, before its data store exists,
  raising a `_doing_it_wrong` notice on every load. Scheduling now happens on `init`.
- Uninstall left the pickup point table and its schema-version option behind, and did not
  cancel the scheduled sync.

## [0.4.0] - 2026-09-02

### Fixed
- Label and pickup list printing. ACS nests the document inside `ACSValueOutput` as an
  object with `Voucher_No` and `PDFData`, not at the top level as first assumed. Found by
  running the real cycle against ACS; fixtures are now recorded rather than invented.
- Smartpoint locker delivery. ACS rejects any product code alongside a Smartpoint
  destination ("An Acs-SmartPoint destination can not be combined with other products"),
  while an ACS *store* requires `REC`. The two are now handled distinctly.

### Added
- Cash on delivery. The amount, collection method and the ACS `COD` product are set from
  the order when an unpaid COD gateway was used, filterable via `acs_wc_cod_gateways`.
- Validation that COD to a pickup point carries a recipient email, which ACS requires.
- Setting for whether COD is collected as cash or cheque.

## [0.3.0] - 2026-09-02

### Added
- WooCommerce shipping method offering ACS home delivery and pickup-point rates,
  configurable per shipping zone.
- Checkout pickup point selector, served from a local indexed table so checkout never
  waits on ACS. Around 1,600 points across Greece and Cyprus.
- Daily pickup point refresh through Action Scheduler.
- The chosen pickup point now routes the voucher, adding the ACS `REC` product.
- Origin station and label format settings.

### Added earlier in this cycle
- Country-aware rate resolution. Greece is priced live by ACS; Cyprus uses a local
  weight-banded table, because `ACS_Price_Calculation` does not support Cyprus.
- A rate table with weight bands, separate home and locker pricing, a per-kilo increment
  above the heaviest band, and a free-shipping threshold.
- `PickupPoint`, mapping ACS store and Smartpoint rows with great-circle distance.
  Verified against 1,620 live points (73 Cypriot lockers, 33 Cypriot stores, 1,514 Greek lockers).

### Planned
- Checkout locker selector, WooCommerce shipping method, cash on delivery.

## [0.2.0] - 2026-09-02

### Added
- Label printing in thermal and A4 laser formats, streamed through an authenticated handler.
- Pickup list workflow. Issuing the list is mandatory, since ACS does not recognise voucher
  barcodes until it exists, and its vouchers can never be deleted afterwards.
- Shipment tracking with status, checkpoint history and English non-delivery reasons.
- Print label and Refresh tracking actions on the order screen.

### Fixed
- A returned parcel is no longer reported as delivered. ACS sets `delivery_flag` to 1 when a
  returned shipment reaches the sender, so delivery now requires the status, the flag and the
  absence of a return.

## [0.1.0] - 2026-09-02

### Added
- ACS REST client with no WordPress dependency, covering both of ACS's error channels.
- Rate throttling (ACS allows 10 calls per second) and bounded exponential backoff.
- Domain model for Greece and Cyprus, including per-country postcode rules and the
  Cyprus content-type requirement.
- Weight handling with the ACS 0.5–999 kg bounds and volumetric calculation.
- Mapping from WooCommerce orders, converting kg, g, lbs and oz to kilograms.
- Voucher creation with local pre-flight validation and an atomic per-order lock.
- Settings screen with `wp-config.php` constant override for credentials.
- Order screen panel for creating a voucher.
- Uninstall handler covering both HPOS and legacy order meta.
