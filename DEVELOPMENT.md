# Development Guide — Email2Order

> Last reviewed: 2026-10-19

## Repository role

```text
canonical source: vanyolai/dolibarr-email2order
source branch:    main
consumer prefix:  htdocs/custom/email2order
subtree mode:     squashed
```

Email2Order must remain an external Dolibarr module. It must not patch Email Collector core files.

## Current implementation state

Current module version: **0.1.0**.

The module converts supplier order-confirmation emails collected by Dolibarr's native Email Collector into **draft** supplier orders.

Current supplier-specific parser profiles include:
- MILE;
- DSC;
- POWER Biztonságtechnika;
- Daniella;
- Delton;
- Overgate;
- RIEL;
- WAGO.

Unknown formats are intentionally classified as `unsupported`. Diagnostic extraction may still recover sender/reference hints, but unsupported input must not create an order.

Existing safety behavior:
- supplier identification is required;
- draft order only — no automatic validation;
- minimum-valid-order gate;
- SHA-256 message fingerprint/idempotency;
- forwarded-message sender recovery;
- native Email Collector hook integration.

## Architectural rules

- Dolibarr Email Collector remains responsible for mail acquisition.
- Email2Order owns parsing/normalization only after collection.
- Supplier-specific layout assumptions must stay inside supplier parsers.
- Generic orchestration must not learn supplier HTML/table quirks.
- Never guess arbitrary order lines from an unknown layout.
- Parser output must pass a common validity gate before creating a supplier order.
- Supplier order creation must use `CommandeFournisseur` APIs.
- Keep every generated order in Draft for human review.
- Persist enough import identity to prevent duplicate order creation.
- Use HU/EN language keys for all user-facing status/error text.

## Testing policy

Every supplier parser should have representative sanitized fixtures covering:
- expected confirmation;
- forwarded confirmation where relevant;
- missing mandatory fields;
- changed/ambiguous layout;
- duplicate import;
- decimal/unit/quantity edge cases.

The generic facade should be tested independently from parser-specific fixtures.

## Roadmap

### Milestone 1 — parser regression baseline
- create/maintain deterministic fixtures for every supported supplier;
- make parser selection and unsupported behavior testable;
- verify all known profiles against real recent confirmation formats.

### Milestone 2 — diagnostics and maintainability
- expose parser name, rejection reason and missing mandatory fields clearly;
- keep parsing failures visible without creating partial orders;
- document supplier-specific assumptions next to each parser.

### Milestone 3 — supplier resolution hardening
- verify direct, redirected and manually forwarded sender resolution;
- ensure ambiguous supplier/contact matches fail safely;
- test entity/permission behavior.

### Milestone 4 — production hardening
- integration-test collector retries and message moves;
- verify idempotency under repeated collection;
- add release/version documentation before expanding parser count further.

## Subtree update

```bash
git subtree pull \
  --prefix=htdocs/custom/email2order \
  https://github.com/vanyolai/dolibarr-email2order.git \
  main \
  --squash \
  -m "Update Email2Order subtree"
```

Development direction is always standalone repo -> subtree. Avoid ad-hoc parser edits only in the consumer.
