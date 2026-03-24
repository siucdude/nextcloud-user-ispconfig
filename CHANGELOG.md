# Changelog

## 0.6.1 (2026-03-24)

### Fixed — Nextcloud 28–33 Compatibility

This release fixes all compatibility issues that broke the plugin on Nextcloud 28+
and makes it fully working on NC31, NC32, and NC33.

#### 1. Replaced `appinfo/app.php` with `IBootstrap` (NC31+)
`app.php` was removed in Nextcloud 31. The app now uses the modern
`OCP\AppFramework\Bootstrap\IBootstrap` interface via `lib/Application.php`,
which is declared in `appinfo/info.xml` via the `<main>` tag.

#### 2. Fixed NC32 login chain breaking change
In Nextcloud 32, the login flow changed: `checkPassword()` now receives the raw
login string typed by the user (e.g. `user@domain.com`) instead of a pre-resolved
mapped UID. The plugin now detects whether it received an email address or a UID
and handles both cases, making it compatible with NC28–33.

#### 3. Fixed class loading — config.php must use full namespace
`OC_User::setupBackends()` calls `class_exists('OC_User_ISPCONFIG')` before the
app boots, so the `class_alias` is not yet registered. The fix is to use the full
namespaced class name in `config.php`:

```php
// WRONG — will log "User backend OC_User_ISPCONFIG not found" on NC31+
'class' => 'OC_User_ISPCONFIG',

// CORRECT
'class' => 'OCA\UserISPConfig\UserISPCONFIG',
```

#### 4. Removed all private Nextcloud internals
The class no longer extends `\OC\User\Backend` (a private class that keeps
breaking between NC versions). It now implements only the stable public interfaces
`OCP\IUserBackend` and `OCP\UserInterface`.

#### 5. Fixed interface method type hints
`OCP\UserInterface` method signatures are untyped. Adding PHP type hints to
implementing methods causes fatal errors. All interface method type hints removed.

#### 6. Fixed user storage table
Uses `oc_users_ispconfig` (the original plugin's table) instead of
`oc_external_users` (which doesn't exist in this installation), preserving all
existing user data and display names.

#### 7. `\OC_User::useBackend` deprecated in NC32
`Application.php` now uses `$userManager->registerBackend()` (available since
NC 8.0) instead of the deprecated static `\OC_User::useBackend()`.

---

## 0.5.0 and earlier

See the original repository for history prior to 0.6.1:
https://github.com/SpicyWeb-de/nextcloud-user-ispconfig
