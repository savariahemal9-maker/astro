# Jyotish — PHP/MySQL astrology site + shared REST API (v1)

## Setup (shared hosting: PHP 8.1+, MySQL/MariaDB)
1. Upload everything, including `data/ephem/` (~45 MB). No exec/compiler needed (`engine.backend = php`).
2. `mysql -u root -p jyotish < database/schema.sql`
3. `cp config/config.example.php config/config.php` and fill in DB, Nominatim user agent, GeoNames username.
4. Point the web root at the project folder (Apache: .htaccess included). API: `/api/v1/...`, site: `/`.
   Local dev: `php -S 127.0.0.1:8088 tests/router_dev.php`
   Optional VPS mode: `bash engine/build.sh`, set `engine.backend = binary`.

## Verify before launch
- `php tests/unit.php` — vargas, dasha, time zones, engine vs published astronomical events
- `python3 tests/crosscheck_astropy.py` — independent position cross-check (needs `pip install astropy`)
- `php tests/compare_reference.php` — fill `tests/fixtures/reference_cases.json` from JHora / Drik Panchang first; must report 0 mismatches, 0 unfilled

## Rules
- Positions come from `data/ephem` (Chebyshev fits of Swiss Ephemeris 2.10.03, Lahiri, 1800–2399), regenerated only with `tools/gen_ephem.py`.
  `tests/verify_php_engine.php` (dev machine with engine/bin) checks PHP vs Swiss Ephemeris. No fallback: failures return `calculation_unavailable`.
- Calculated data (`meta.type = calculated`) and interpretations (`meta.type = interpretation`) are separate endpoints and tables.
- Interpretation rules in `src/Interp/RuleEngine.php` and texts in `lang/*.php` are drafts — astrologer review required.
- Swiss Ephemeris is AGPL: open-source this project or buy the Astrodienst commercial license.
