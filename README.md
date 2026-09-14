# Dolibarr Email2Order

Email2Order is an external module for Dolibarr 23.x that turns supplier order-confirmation emails collected by Dolibarr's built-in Email Collector into draft supplier orders (`CommandeFournisseur`).

> Status: early bootstrap / proof of concept. The generic parser creates the supplier-order header and preserves the source email. Supplier-specific line parsers will be added from real confirmation samples.

## Design

The module does **not** patch Dolibarr core. It uses the existing Email Collector hooks:

- `emailcollectorcard` to add the **Create supplier order from email** operation to the Email Collector UI;
- `emailcolector` (the context name used by Dolibarr 23.0 runtime) to process a collected message through `doCollectImapOneCollector`.

The resulting supplier order is deliberately left in **Draft** status for review.

The repository root is the module root. When embedded in the Dolibarr fork it should live at:

```text
htdocs/custom/email2order/
```

## Add to the Dolibarr 23.0 fork as a git subtree

Run this from a local clone of `vanyolai/dolibarr`:

```bash
git checkout 23.0

git remote add email2order git@github.com:vanyolai/dolibarr-email2order.git
git fetch email2order

git subtree add \
  --prefix=htdocs/custom/email2order \
  email2order main \
  --squash

git push origin 23.0
```

The remote is stored in the local repository's `.git/config`; Git remotes cannot be registered in the GitHub repository itself.

### Pull later Email2Order changes into Dolibarr

```bash
git fetch email2order
git subtree pull \
  --prefix=htdocs/custom/email2order \
  email2order main \
  --squash
```

### Push subtree changes back to the standalone repository

```bash
git subtree push \
  --prefix=htdocs/custom/email2order \
  email2order main
```

## First test setup

1. Enable Dolibarr's **Suppliers / Supplier Orders** and **Email Collector** modules.
2. Enable **Email2Order**.
3. Create a dedicated IMAP mailbox for order confirmations.
4. Create an Email Collector for that mailbox.
5. Add suitable collector filters (for the first test, `unseen` is enough).
6. Add the Email Collector operation **Create draft supplier order from email (Email2Order)**.
7. Set a target IMAP folder such as `Processed` so successfully handled messages are moved away from the inbox.
8. Forward or redirect one supplier confirmation into the mailbox and run **Test collect now** first.

For direct/redirected mail, the collector's detected third party is used. For manually forwarded messages Email2Order also tries to recover the original sender from common forwarded-message headers (`From`, `Feladó`, `Felado`, `Von`) and resolve that address to a Dolibarr supplier or supplier contact.

If no supplier can be identified, processing fails intentionally and no order is created.

## Current parser behavior

The generic parser currently extracts:

- original sender email from forwarded content when possible;
- a conservative supplier confirmation/reference number from subject/body when a recognizable `order`, `confirmation`, `visszaigazolás`, or `rendelés` label is present.

It does not yet guess arbitrary line-item tables. The first real supplier confirmation will be used to implement the first supplier-specific parser.

## Idempotency

Successful imports are recorded in `llx_email2order_import` using a SHA-256 message fingerprint. Re-running the collector for the same email does not create another supplier order.

## License

GPL-3.0-or-later, matching Dolibarr's module ecosystem.
