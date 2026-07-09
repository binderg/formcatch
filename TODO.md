# FormCatch — Marketplace Readiness TODO

## Freemius (marketplace side)
- [ ] Upload real product icon/logo in Freemius dashboard (fixes checkout page branding)
- [ ] Upload banner images, screenshots in Freemius dashboard
- [ ] Set up real Pricing/Plans + license limits in dashboard (currently only sandbox/dev tested)
- [ ] Decide: keep or remove `wp_org_gatekeeper` in `includes/fc-freemius.php` — keep only if free version also ships on wp.org
- [ ] Test full purchase flow with a real (non-sandbox) test payment
- [ ] Verify webhook/API keys in Freemius dashboard point to production, not sandbox

## wp-config / environment
- [ ] Confirm `WP_FS__DEV_MODE` / `WP_FS__SKIP_EMAIL_ACTIVATION` are NOT set on production site (local-dev only)

## Plugin assets
- [x] Dropped `Domain Path: /languages` from plugin header (not using translations)
- [x] Added `/assets` folder with wp.org-sized icon/banner images (icon-128/256, banner-772x250/1544x500)
- [x] Fixed banner filenames — wp.org matches them exactly (`banner-1544x500.jpg`, not `banner_1554x500.jpg`)
- [x] Produce the 5 screenshots promised in `readme.txt` (`assets/screenshot-1.png` … `screenshot-5.png`)

## Code/security pass
- [x] No debug leftovers (`var_dump`, `console.log`, TODO/FIXME) — clean
- [x] No hardcoded secrets — clean
- [x] Opt-in-gated data deletion, now on Freemius `after_uninstall` (Freemius forbids `uninstall.php`)
- [x] Nonce + capability on all 12 `admin_post` handlers; file downloads use HMAC signed URLs w/ `hash_equals`

## Version/readme
- [x] `readme.txt` stable tag matches `FC_VERSION` in `formcatch.php` — keep in sync every release
- [x] Added `== Description ==`, `== Installation ==`, `== Screenshots ==`, `Tags:`, `License URI:`, short description
- [x] Added `== External services ==` disclosure (Freemius, reCAPTCHA, hCaptcha, Turnstile, GitHub blocklist) — required by wp.org
- [x] Bumped `Tested up to: 7.0`
- [x] Confirmed `Contributors: GStudios` matches the real wordpress.org username

## Packaging
- [x] Added `.distignore` (excludes `assets/`, `TODO.md`, dev cruft — keeps `vendor/freemius`, required at runtime)
- [ ] Upload the Freemius-generated **free** build to wp.org, not this repo's zip (this build is `is_premium => true`)
- [ ] Clean-install smoke test: fresh WP, activate, no fatals, DB tables created, free-tier gating (webhooks + REST API) locked pre-license

## Final QA
- [ ] License activate → deactivate → reactivate cycle
- [ ] Plugin update flow (Freemius auto-updater) with a version bump
- [ ] Upgrade banner + PRO badge toggle correctly on license state change
