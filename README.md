# OxyDDT – Italian Delivery Notes (DDT) for WooCommerce

Issue Italian *documenti di trasporto* from WooCommerce orders: partial
fulfilment, numbering that cannot collide, documents that do not change once
issued, and a PDF.

Part of the [OxyWP](https://oxywp.com/) family, alongside OxyProfit, OxyArea and
WP Easy.

## Requirements

| | |
|---|---|
| PHP | 8.1 or later |
| WordPress | 6.5 or later |
| WooCommerce | 8.2 or later |

## Development

```bash
composer install
composer check          # coding standards, PHPStan level 8, unit tests
```

The **unit** suite is plain PHP and runs anywhere, with no database. The
**integration** suite needs WordPress, WooCommerce and a database it is allowed
to destroy:

```bash
scripts/wordpress-test-env.sh >env.txt
WP_PHPUNIT__TESTS_CONFIG="$(sed -n 1p env.txt)" \
WP_WOOCOMMERCE_DIR="$(sed -n 2p env.txt)" \
composer test:integration
```

Both suites, the coding standards, static analysis and a job that builds the
distributable package run in GitHub Actions on every push.

## Layout

```
src/Domain/          plain PHP: addresses, tax identifiers, the sender
src/Infrastructure/  container, schema, clock, WooCommerce facts
src/Security/        capabilities
src/Settings/        the option row
src/Audit/           the append-only register
src/Admin/           the WooCommerce → DDT screen
docs/                specification, identity, architecture, release gates
```

## Documentation

* [`docs/00-specifica-originale.md`](docs/00-specifica-originale.md) — the brief this was built from
* [`docs/01-identita.md`](docs/01-identita.md) — names, prefixes, and what is still open
* [`docs/02-architettura.md`](docs/02-architettura.md) — how it is put together, and why
* [`docs/03-rilascio.md`](docs/03-rilascio.md) — what has to be true before a release

## Licence

GPL-2.0-or-later.
