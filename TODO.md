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

## Plugin assets (missing)
- [ ] Add `/assets` folder for wp.org listing: icon-128x128, banner-772x250, screenshots (if listing free version on wp.org)
- [x] Dropped `Domain Path: /languages` from plugin header (not using translations)
- [x] Added `/assets` folder with wp.org-sized icon/banner images (icon-128/256, banner-772x250/1544x500)

## Code/security pass
- [x] No debug leftovers (`var_dump`, `console.log`, TODO/FIXME) — clean
- [x] No hardcoded secrets — clean
- [x] `uninstall.php` opt-in-gated data deletion — matches WP.org guidelines

## Version/readme
- [x] `readme.txt` stable tag matches `FC_VERSION` in `formcatch.php` — keep in sync every release
- [ ] Add standard wp.org readme sections if listing free version there: `== Description ==`, `== Installation ==`, `== Screenshots ==`, `Tags:`, `Donate link:`

## Packaging
- [ ] Add `.distignore` (exclude `.git`, tests, dev cruft — keep `vendor/freemius`, it's required at runtime)
- [ ] Clean-install smoke test: fresh WP, activate, no fatals, DB tables created, free-tier gating (CAPTCHA/uploads/webhooks/REST) locked pre-license

## Final QA
- [ ] License activate → deactivate → reactivate cycle
- [ ] Plugin update flow (Freemius auto-updater) with a version bump
- [ ] Upgrade banner + PRO badge toggle correctly on license state change
