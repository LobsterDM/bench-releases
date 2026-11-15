# sodium_compat (Ed25519 verification only)

Bench uses these files to check release signatures on hosts whose PHP has no sodium
extension (`bench_ed25519_verify()` in `core/updater.php`). They're only loaded there.

- Source: https://github.com/paragonie/sodium_compat, commit 3246b36803c177847e677862fedeb7f937869adb (August 2026)
- Licence: ISC (see LICENSE)
- Copied unchanged: `src/Compat.php`, `src/SodiumException.php`, `src/Core/Util.php`,
  `src/Core/Curve25519.php` and `src/Core/Curve25519/`, `src/Core/Ed25519.php`.
  Nothing else from the library is needed for verification.
