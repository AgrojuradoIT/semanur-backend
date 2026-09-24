# Operational scripts

These helpers are intentionally outside `public/` and reject non-CLI execution.
Run them only from an authenticated terminal or deployment pipeline:

```bash
php scripts/operations/deploy.php
php scripts/operations/clear_cache.php
php scripts/operations/link_storage.php
```

`deploy.php` never runs migrations. Production database changes are applied from
the reviewed SQL package after backup, preflight, and rollback checks.
