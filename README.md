# Annual Ranking – Event Columns (v8)
- Identity across events is based on (fname, lname) — no reliance on per-event `id`.
- Per-event aggregation groups by (class, fname, lname).
- Filters: non-empty opis, exclude '22LR', exclude 'test' anywhere (case-insensitive).
- ME detection: 'EUROPEAN CHAMPIONSHIPS' (case-insensitive).
- All event dates as columns. Class mapping 1..7. No ID column. CSV numeric-only.

## Release checklist
- [ ] Update DB credentials in `app/config.php`
- [ ] Verify event tables exist for the year
- [ ] Confirm ME phrase (EUROPEAN CHAMPIONSHIPS)
- [ ] Check class labels & order
- [ ] Smoke-test UI & CSV
- [ ] Validate against raw tables

## Troubleshooting
- No data: check `zawody.data` and day tables.
- Shooter without ME: verify ME date and scores > 0.
- Decimals: `number_format(..., 0, ...)` in UI/CSV.
- Wrong class order: `$desiredOrder` in index.php.
- Encoding: set `source_charset` in config.
