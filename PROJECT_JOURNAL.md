# nextcloud-user-ispconfig — NC28–33 Compatibility Fix
## Project Journal: What Broke, How We Debugged It, How We Fixed It

**Date:** March 24, 2026
**Nextcloud version:** 31.0.14 (upgrading toward 32/33)
**ISPConfig version:** 3.3.1p1
**PHP version:** 8.3
**Server OS:** Debian/Ubuntu via ISPConfig (ISPConfig PHP-FPM pool)
**Original plugin:** SpicyWeb-de/nextcloud-user-ispconfig v0.5.0
**Fork:** siucdude/nextcloud-user-ispconfig

---

## The Goal

Keep the existing ISPConfig SOAP-based user authentication working as Nextcloud
upgrades from 31 → 32 → 33. The original plugin was last updated for NC27 and
was officially abandoned by its author.

---

## Final File Structure

```
user_ispconfig/
├── appinfo/
│   └── info.xml
├── lib/
│   ├── Application.php      ← NEW (replaces appinfo/app.php)
│   └── UserISPCONFIG.php    ← REWRITTEN
├── CHANGELOG.md
└── README.md
```

**Deleted:** `appinfo/app.php` (no longer supported since NC31)
**Deleted:** `appinfo/database.xml` (not supported since NC22)

---

## Critical config.php Change Required

This is the single most important change for anyone upgrading.

```php
// ❌ OLD — causes "User backend OC_User_ISPCONFIG not found" on NC31+
'class' => 'OC_User_ISPCONFIG',

// ✅ NEW — required for NC31+
'class' => 'OCA\UserISPConfig\UserISPCONFIG',
```

Why: `OC_User::setupBackends()` calls `class_exists()` on the class name from
`config.php` before the app boots. The `class_alias` that maps the old name to
the new class only gets registered when the app file is loaded — which is after
`setupBackends()` runs. Using the full namespaced name lets PHP's PSR-4
autoloader find the class immediately without needing the alias.

---

## All Bugs Found and Fixed

### Bug 1: `appinfo/app.php` removed in NC31

**Symptom:**
```
Error user_ispconfig: /appinfo/app.php is not supported anymore,
use \OCP\AppFramework\Bootstrap\IBootstrap on the application class instead.
```

**Root cause:** NC31 removed support for `appinfo/app.php` entirely. All apps
must now use the `IBootstrap` interface.

**Fix:** Deleted `appinfo/app.php`. Created `lib/Application.php`:

```php
<?php
declare(strict_types=1);
namespace OCA\UserISPConfig;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;

class Application extends App implements IBootstrap {

    public const APP_ID = 'user_ispconfig';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {}

    public function boot(IBootContext $context): void {
        $context->injectFn([$this, 'registerBackend']);
    }

    public function registerBackend(IUserManager $userManager): void {
        $config   = \OC::$server->getConfig();
        $backends = $config->getSystemValue('user_backends', []);
        foreach ($backends as $backend) {
            $class = $backend['class'] ?? '';
            if ($class !== 'OC_User_ISPCONFIG' && $class !== UserISPCONFIG::class) {
                continue;
            }
            $args     = $backend['arguments'] ?? [];
            $instance = new UserISPCONFIG(
                $args[0] ?? '', $args[1] ?? '',
                $args[2] ?? '', $args[3] ?? '',
                $args[4] ?? []
            );
            $userManager->registerBackend($instance);
        }
    }
}
```

Also added `<main>OCA\UserISPConfig\Application</main>` to `appinfo/info.xml`.

---

### Bug 2: `info.xml` max-version capped at 27

**Symptom:** NC28+ silently refused to load the app.

**Fix:** Updated `appinfo/info.xml`:
```xml
<version>0.6.1</version>
<dependencies>
    <php min-version="8.0" max-version="8.4"/>
    <nextcloud min-version="28" max-version="33"/>
</dependencies>
```

Also changed `<n>` back to `<name>` which is what NC expects (the tag name
matters for the app store schema validator).

---

### Bug 3: App auto-disabling itself on every web request

**Symptom:** `occ app:enable user_ispconfig` succeeds, but `occ app:list`
immediately shows the app in the **disabled** section (leading dash). Trying
to log in produces "User backend not found" errors.

**Debugging process:**

```bash
# Step 1: Confirmed app.php was gone from disk
find /var/www/your-nextcloud/apps/user_ispconfig -type f
# → app.php was NOT present, so that wasn't it

# Step 2: Tested PHP class loading directly
sudo -u YOUR-WEB-USER php -r "
  define('OC_CONSOLE', 1);
  chdir('/var/www/your-nextcloud/web');
  require_once 'lib/base.php';
  require_once 'apps/user_ispconfig/lib/UserISPCONFIG.php';
  require_once 'apps/user_ispconfig/lib/Application.php';
  echo 'OK';
" 2>&1
# → PHP Fatal error: Declaration of implementsActions(int $actions): bool
#   must be compatible with OCP\UserInterface::implementsActions($actions)
```

**Root cause:** `OCP\UserInterface` on this version of NC has **no type hints**
on any of its method signatures. Adding stricter type hints in the implementing
class causes a PHP fatal error, which NC catches and responds to by
auto-disabling the app.

**Fix:** Removed all type hints from every interface method:

```php
// ❌ WRONG — causes fatal on NC31
public function implementsActions(int $actions): bool { ... }
public function checkPassword(string $loginName, string $password): string|false { ... }
public function userExists(string $uid): bool { ... }
public function getDisplayName(string $uid): string { ... }
public function getDisplayNames(string $search = '', ?int $limit = null, ?int $offset = null): array { ... }
public function getUsers(string $search = '', ?int $limit = null, ?int $offset = null): array { ... }
public function countUsers(): int|false { ... }
public function deleteUser(string $uid): bool { ... }

// ✅ CORRECT — matches OCP\UserInterface signature exactly
public function implementsActions($actions) { ... }
public function checkPassword($loginName, $password) { ... }
public function userExists($uid) { ... }
public function getDisplayName($uid) { ... }
public function getDisplayNames($search = '', $limit = null, $offset = null) { ... }
public function getUsers($search = '', $limit = null, $offset = null) { ... }
public function countUsers() { ... }
public function deleteUser($uid) { ... }
```

Also discovered `hasUserListings()` was missing entirely — NC requires it:
```php
public function hasUserListings() { return false; }
```

---

### Bug 4: Extending `\OC\User\Backend` caused fatal errors

**Symptom:** Fatal error on class load, app auto-disables.

**Root cause:** `\OC\User\Backend` is a **private** Nextcloud class (in the
`\OC\` namespace, not `\OCP\`). Its interface keeps changing between NC versions.
Extending it broke between NC30 and NC31.

**Fix:** Stopped extending it entirely. The class now only implements the two
stable **public** interfaces:

```php
// ❌ OLD
class UserISPCONFIG extends \OC\User\Backend implements IUserBackend, UserInterface

// ✅ NEW
class UserISPCONFIG implements IUserBackend, UserInterface
```

The only thing `\OC\User\Backend` provided was `storeUser()` and
`userExistsInDatabase()` — both were reimplemented directly (see Bug 6).

---

### Bug 5: NC32 login chain breaking change

**Symptom:** Users could not log in on NC32 even with the app correctly loaded.
Password verification worked but `checkPassword()` returned false.

**Root cause:** In NC32, Nextcloud changed how it calls `checkPassword()`.
Previously NC resolved the typed login (e.g. `john@domain.com`) to a mapped
UID (e.g. `john`) first, then passed the UID to `checkPassword()`. From NC32
onwards, NC passes the **raw login string** (the email address) directly.

The old plugin assumed it always received a UID and tried to look up `john`
in ISPConfig instead of `john@domain.com`.

**Fix:** Detect which format we received based on whether the string contains `@`:

```php
public function checkPassword($loginName, $password) {
    $mapUids = (bool)($this->options['map_uids'] ?? true);
    $isEmail = str_contains($loginName, '@');

    if ($isEmail) {
        // NC32+ path: received raw email address, use directly as SOAP login
        $soapLogin = $loginName;
    } elseif ($mapUids) {
        // NC<=31 path: received a mapped UID, reverse it to get the email
        $reversed  = $this->reverseMappedUidToEmail($loginName);
        $soapLogin = ($reversed !== false) ? $reversed : $loginName;
    } else {
        // map_uids=false: loginName IS the ISPConfig login already
        $soapLogin = $loginName;
    }
    // ... rest of SOAP call
}
```

---

### Bug 6: `oc_external_users` table does not exist

**Symptom:**
```
Error [UserISPConfig] storeUser: SQLSTATE[42S02]: Base table or view not found:
1146 Table 'your_database.oc_external_users' doesn't exist
```

**Root cause:** Our rewrite used `oc_external_users` (the table used by the
`user_external` app) to record which users belong to this backend. But this
installation never had that app installed, so the table doesn't exist.

The original plugin used its own table `oc_users_ispconfig` with this schema:
```sql
uid         varchar(64) PRIMARY KEY
displayname varchar(64)
mailbox     varchar(64)
domain      varchar(64)
```

**Fix:** Changed `storeUser()` and `userExists()` to use `oc_users_ispconfig`:

```php
private function storeUser($uid) {
    try {
        $db = \OC::$server->getDatabaseConnection();
        $qb = $db->getQueryBuilder();
        $exists = $qb->select('uid')->from('users_ispconfig')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->executeQuery()->fetchOne();
        if ($exists === false) {
            $parts   = explode('@', $uid, 2);
            $mailbox = $parts[0];
            $domain  = $parts[1] ?? '';
            $qb2 = $db->getQueryBuilder();
            $qb2->insert('users_ispconfig')->values([
                'uid'         => $qb2->createNamedParameter($uid),
                'displayname' => $qb2->createNamedParameter($uid),
                'mailbox'     => $qb2->createNamedParameter($mailbox),
                'domain'      => $qb2->createNamedParameter($domain),
            ])->executeStatement();
        }
    } catch (\Throwable $e) {
        $this->logError('storeUser: ' . $e->getMessage());
    }
}

public function userExists($uid) {
    try {
        $db = \OC::$server->getDatabaseConnection();
        $qb = $db->getQueryBuilder();
        $result = $qb->select('uid')->from('users_ispconfig')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->executeQuery()->fetchOne();
        return $result !== false;
    } catch (\Throwable $e) { return false; }
}
```

Note: NC's query builder automatically prepends the `oc_` table prefix, so
`'users_ispconfig'` in the query becomes `oc_users_ispconfig` in SQL.

---

### Bug 7: "User backend OC_User_ISPCONFIG not found" — the root cause

**Symptom:** Even with the app enabled and all PHP errors fixed, every web
request logged this error and users could not log in.

**Investigation:**
```bash
# Found the source of the error message
grep -r "User backend.*not found" /var/www/your-nextcloud/web/lib/
# → lib/private/legacy/OC_User.php line 125

# Read the code
sed -n '95,130p' lib/private/legacy/OC_User.php
```

The relevant code in `OC_User::setupBackends()`:
```php
$class = $config['class'];  // reads 'OC_User_ISPCONFIG' from config.php
if (class_exists($class)) {
    // instantiate and register backend
} else {
    $logger->error('User backend ' . $class . ' not found.');
}
```

**Root cause:** `OC_User::setupBackends()` is called very early in the NC
bootstrap (before apps boot). It reads the `class` value from `config.php` and
calls `class_exists()` on it. At that point our app hasn't booted yet, so the
`class_alias('OCA\UserISPConfig\UserISPCONFIG', 'OC_User_ISPCONFIG')` at the
bottom of our file hasn't run. `class_exists('OC_User_ISPCONFIG')` returns
false, logs the error, and skips registration.

Then when our app finally boots and calls `$userManager->registerBackend()`,
NC is already past the point where it processes login attempts — so the backend
is registered too late and logins fail.

**Fix:** Change `config.php` to use the full namespaced class name:
```php
'class' => 'OCA\UserISPConfig\UserISPCONFIG',
```

PHP's PSR-4 autoloader can resolve `OCA\UserISPConfig\UserISPCONFIG` to
`apps/user_ispconfig/lib/UserISPCONFIG.php` immediately without needing the
app to boot first.

**Verified with:**
```bash
cat > /tmp/test.php << 'EOF'
<?php
define('OC_CONSOLE', 1);
chdir('/var/www/your-nextcloud/web');
require_once 'lib/base.php';
OC_User::setupBackends();
echo 'setupBackends OK' . PHP_EOL;
$backends = OC::$server->getUserManager()->getBackends();
foreach($backends as $b) {
    echo 'Backend: ' . get_class($b) . ' -> ' . $b->getBackendName() . PHP_EOL;
}
EOF
sudo -u YOUR-WEB-USER php /tmp/test.php

# Output:
# setupBackends OK
# Backend: OC\User\Database -> Database
# Backend: OCA\UserISPConfig\UserISPCONFIG -> ISPConfig  ✓
```

---

## Debugging Commands Reference

These commands were useful throughout the debugging process:

```bash
# Check what PHP version the web pool uses
cat /etc/php/8.3/fpm/pool.d/YOUR-WEB-USER.conf | grep php_admin

# Test class loading without a full web request
sudo -u YOUR-WEB-USER php -r "
  define('OC_CONSOLE', 1);
  chdir('/var/www/your-nextcloud/web');
  require_once 'lib/base.php';
  require_once 'apps/user_ispconfig/lib/UserISPCONFIG.php';
  require_once 'apps/user_ispconfig/lib/Application.php';
  echo 'OK';
" 2>&1

# Check app enabled/disabled status
sudo -u YOUR-WEB-USER php occ app:list | grep ispconfig

# Enable with full error output
sudo -u YOUR-WEB-USER php -d display_errors=1 -d error_reporting=E_ALL \
  occ app:enable user_ispconfig 2>&1

# Tail the Nextcloud log
sudo -u YOUR-WEB-USER php occ log:tail 20

# Check actual web request errors (more detailed than NC log)
tail -50 /var/log/ispconfig/httpd/your-nextcloud-domain.com/error.log

# Restart PHP-FPM to clear opcache after changes
systemctl restart php8.3-fpm

# Verify backend registers correctly
sudo -u YOUR-WEB-USER php /tmp/test.php  # see test script above

# Check database tables
mysql -u DB_USER -pDB_PASS DB_NAME \
  -e "SHOW TABLES LIKE '%user%';" \
  -e "SELECT uid, displayname FROM oc_users_ispconfig LIMIT 5;" \
  -e "SELECT appid, configkey, configvalue FROM oc_appconfig WHERE appid='user_ispconfig';"
```

---

## Summary of All Changes

| File | Change |
|------|--------|
| `appinfo/app.php` | **DELETED** — not supported since NC31 |
| `appinfo/info.xml` | Version → 0.6.1, max-version → 33, added `<main>` tag |
| `lib/Application.php` | **NEW** — IBootstrap entry point replacing app.php |
| `lib/UserISPCONFIG.php` | **REWRITTEN** — see details below |
| `config/config.php` | **class name changed** — must use full namespace |

### UserISPCONFIG.php changes summary

| What | Old | New |
|------|-----|-----|
| Class declaration | `extends \OC\User\Backend implements ...` | `implements IUserBackend, UserInterface` only |
| Interface method types | Fully typed (`string $uid): bool`) | Untyped (matches OCP\UserInterface) |
| `hasUserListings()` | Missing | Added (returns false) |
| `checkPassword()` | Assumed UID input | Handles both email (NC32+) and UID (NC≤31) |
| `storeUser()` | Used `oc_external_users` | Uses `oc_users_ispconfig` |
| `userExists()` | Always returned false | Checks `oc_users_ispconfig` |
| Bootstrap registration | `\OC_User::useBackend()` in app.php | `$userManager->registerBackend()` in Application.php |

---

## Installation / Upgrade Instructions

### Fresh install
```bash
cd /var/www/your-nextcloud/apps/
git clone https://github.com/siucdude/nextcloud-user-ispconfig.git user_ispconfig
chown -R www-data:www-data user_ispconfig
sudo -u www-data php occ app:enable user_ispconfig
```

Update `config/config.php` with your ISPConfig credentials (see README.md).

### Upgrading from 0.5.x

1. **Back up outside the apps directory:**
   ```bash
   mv /var/www/nextcloud/apps/user_ispconfig /tmp/user_ispconfig.bak
   ```
   > ⚠️ Never rename to `.bak` inside the `apps/` folder — NC treats every
   > directory in `apps/` as an installed app regardless of name.

2. **Deploy new version:**
   ```bash
   cd /var/www/nextcloud/apps/
   git clone https://github.com/siucdude/nextcloud-user-ispconfig.git user_ispconfig
   chown -R www-data:www-data user_ispconfig
   ```

3. **Update `config/config.php`** — change the class name:
   ```php
   'class' => 'OCA\UserISPConfig\UserISPCONFIG',
   ```

4. **Re-enable and restart:**
   ```bash
   sudo -u www-data php occ app:disable user_ispconfig
   sudo -u www-data php occ app:enable user_ispconfig
   systemctl restart php8.3-fpm   # adjust PHP version as needed
   ```

5. **Verify:**
   ```bash
   sudo -u www-data php occ app:list | grep ispconfig
   # Should show WITHOUT a leading dash (enabled):
   #   user_ispconfig: 0.6.1
   ```
