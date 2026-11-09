# FintsSync – FinTS bank sync for Dolibarr

Dolibarr module (v1.0.0) that imports bank transactions via **FinTS/HBCI**, matches incoming payments to open customer invoices (including per-customer **skonto**), and books the payments. PayPal transactions can be imported as well, and stored transactions can be exported as monthly CSV files.

## Authorship

This module was written by **Cline** (an AI coding agent, powered by Claude). Please review the code before using it with real banking data.

## What it does

- **Bank import (FinTS)**: logs in at your bank using [`nemiah/php-fints`](https://github.com/nemiah/php-fints), lists the accounts, and fetches new statement transactions. Accounts whose IBANs are on the ignore list are skipped. Transactions are stored in `llx_fintssync_tx`.
- **TAN handling**: if the bank asks for a TAN or an app approval (decoupled), the session is saved encrypted. You can continue on the sync page by entering the TAN or confirming that you approved it in the app.
- **Invoice matching**: each credit is matched to open customer invoices. It looks for the invoice reference in the payment purpose and falls back to the counterparty IBAN. It recognises full payments, skonto payments (percentage and optional deadline in days after the invoice date), and payments that fit neither (late, wrong amount, or no match).
- **Booking modes** (per customer, with a global default):
  - `auto`: clear matches are booked as payments automatically.
  - `review`: matches are only proposed, and you confirm, ignore or reopen them in the list.
- **PayPal import** (optional): fetches PayPal transactions through the REST API, including fees, invoice number and event code.
- **Cron job**: a daily job syncs the last days. It also writes the CSV export of the previous month if one doesn't exist yet. If the sync didn't complete (TAN needed, error), the file is named `_INCOMPLETE` and is replaced once a later run succeeds.
- **CSV export**: monthly export (`;` separated, UTF-8 with BOM), available from the UI and the REST API.
- **REST API** (needs the Dolibarr API module and the `fintssync` permissions):
  - `GET /fintssync/exports`
  - `GET /fintssync/exports/{month}`
  - `GET /fintssync/transactions/{month}`
  - `POST /fintssync/exports/{month}`

  `{month}` is formatted `YYYY-MM`.
- Translations: English and German.

## Requirements

- Dolibarr 19.0 or newer
- PHP 8.1 or newer
- Composer, to install `nemiah/php-fints` ^4.1
- A FinTS-capable bank account and a FinTS product registration number

## Installation

1. Put the folder at `htdocs/custom/fintssync`.
2. Install the dependencies (`vendor/` is git-ignored):
   ```
   cd htdocs/custom/fintssync
   composer install
   ```
3. In Dolibarr, go to *Home → Setup → Modules* and enable **FinTS bank sync**. This creates the tables and the cron job.

## Configuration

Open the module setup page (`admin/setup.php`) and set:

| Setting | Meaning |
|---|---|
| FinTS URL, bank code (BLZ), user, PIN | Bank access. The PIN is stored encrypted. |
| Product registration number | FinTS product ID |
| TAN mode, TAN medium | Use the "List TAN modes" button to find the right values |
| Days of history | Keep this below 30. Longer periods need a TAN. |
| IBANs to ignore | Comma separated. All other accounts are synced. |
| Default booking mode | `review` or `auto` |
| Dolibarr bank account | Optional. Payments are linked to it if the Bank module is enabled. |
| PayPal client ID / secret | Optional |

On each customer card, set the allowed **skonto percentage**, the **skonto deadline** and the **booking mode**.

## Usage

- **Sync page** (`sync.php`): run a sync now and enter a TAN if requested.
- **List page** (`list.php`): filter by Review / Booked / Ignored / All, then confirm, ignore or reopen transactions.
- **Export page** (`export.php`): create or download monthly CSV files.

## Layout

```
admin/setup.php         Module setup
class/                  Client, matcher, PayPal, export, cron, REST API
core/modules/           Dolibarr module descriptor (modFintsSync)
langs/                  en_US, de_DE
sql/                    Tables: fintssync_tx, fintssync_customer
test/matcher_test.php   Tests for the matcher
list.php sync.php customer.php export.php   UI pages
```

## License

GPL-3.0-or-later (see `composer.json`).
