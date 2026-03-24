# User ISPConfig

Authenticate Nextcloud users against the ISPConfig Mailuser API via SOAP.

**Compatible with: Nextcloud 28–33, PHP 8.0–8.4, ISPConfig 3.x**

> This is a community-maintained fork of the original
> [SpicyWeb-de/nextcloud-user-ispconfig](https://github.com/SpicyWeb-de/nextcloud-user-ispconfig)
> plugin, updated to work with Nextcloud 28–33.

---

## Installation

### From this repository

```bash
cd /var/www/your-nextcloud/apps/
git clone https://github.com/siucdude/nextcloud-user-ispconfig.git user_ispconfig
chown -R www-data:www-data user_ispconfig   # adjust user as needed
sudo -u www-data php /var/www/your-nextcloud/occ app:enable user_ispconfig
```

### Upgrading from 0.5.x

1. Back up the old folder **outside** the `apps/` directory:
   ```bash
   mv apps/user_ispconfig /tmp/user_ispconfig.bak
   ```
2. Clone/copy the new version into `apps/user_ispconfig/`
3. **Update `config/config.php`** — this is required (see below)
4. Re-enable: `sudo -u www-data php occ app:enable user_ispconfig`

---

## Configuration

### Prerequisites

This plugin uses the ISPConfig 3 SOAP API. Create a remote API user in your
ISPConfig panel under **System → Remote Users** with permissions for:
- Customer Functions
- Server Functions
- E-Mail User Functions

### ⚠️ Critical: config.php class name change

On Nextcloud 31+, you **must** use the full namespaced class name.
The old `OC_User_ISPCONFIG` shortname no longer works:

```php
// ❌ OLD — does NOT work on NC31+
'class' => 'OC_User_ISPCONFIG',

// ✅ NEW — required for NC31+
'class' => 'OCA\UserISPConfig\UserISPCONFIG',
```

### Basic configuration

```php
<?php
$CONFIG = array(
    'user_backends' => array(
        0 => array(
            'class' => 'OCA\UserISPConfig\UserISPCONFIG',
            'arguments' => array(
                0 => 'https://YOUR.PANEL.FQDN:PORT/remote/index.php',
                1 => 'https://YOUR.PANEL.FQDN:PORT/remote/',
                2 => 'YOUR_REMOTE_API_USER',
                3 => 'YOUR_REMOTE_API_PASS',
            ),
        ),
    ),
);
```

This allows any ISPConfig mail user to log in with their email address and
password. A Nextcloud account is created automatically on first login.

### Extended configuration

Pass a 5th argument (index 4) for additional options:

```php
<?php
$CONFIG = array(
    'user_backends' => array(
        0 => array(
            'class' => 'OCA\UserISPConfig\UserISPCONFIG',
            'arguments' => array(
                0 => 'https://YOUR.PANEL.FQDN:PORT/remote/index.php',
                1 => 'https://YOUR.PANEL.FQDN:PORT/remote/',
                2 => 'YOUR_REMOTE_API_USER',
                3 => 'YOUR_REMOTE_API_PASS',
                4 => array(
                    'allowed_domains' => array(
                        'domain-one.com',
                        'domain-two.net',
                    ),
                    'default_quota'  => '20000M',
                    'default_groups' => array('users'),
                    'domain_config'  => array(
                        'domain-one.com' => array(
                            'quota'     => '50000M',
                            'groups'    => array('users', 'company'),
                            'bare-name' => true,   // login as "john" not "john@domain-one.com"
                        ),
                        'domain-two.net' => array(
                            'quota'      => '10000M',
                            'uid-suffix' => '.two', // login as "john.two"
                        ),
                    ),
                ),
            ),
        ),
    ),
);
```

### Global options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `map_uids` | bool | `true` | Map email addresses to clean UIDs using domain_config rules |
| `allowed_domains` | string[] | `false` | Whitelist — only these domains can log in |
| `default_quota` | string | `false` | Default quota for new users (e.g. `500M`, `2G`) |
| `default_groups` | string[] | `false` | Auto-add new users to these groups on first login |
| `preferences` | array | `false` | Default app preferences for new users |

### Per-domain options (`domain_config`)

| Option | Type | Description |
|--------|------|-------------|
| `quota` | string | Quota for users of this domain |
| `groups` | string[] | Additional groups for users of this domain |
| `bare-name` | bool | Login with mailbox name only (e.g. `john` instead of `john@domain.com`) |
| `uid-prefix` | string | Prefix the mailbox name (e.g. `prefix-john`) |
| `uid-suffix` | string | Suffix the mailbox name (e.g. `john.suffix`) |
| `preferences` | array | Per-domain app preferences |

### UID mapping

When `map_uids` is true (default), the plugin maps email addresses to clean
Nextcloud UIDs. The mapping order per domain is:

1. `bare-name` → `john`
2. `uid-prefix` → `prefix-john`
3. `uid-suffix` → `john.suffix`
4. (none) → `john@domain.com`

**Do not change UID mapping in production** — existing user data is tied to
the UID and changing it will effectively create duplicate accounts.

---

## Troubleshooting

### "Invalid password" on login

1. **Check PHP SOAP extension:**
   ```bash
   php -m | grep soap
   # If missing:
   apt-get install php-soap && phpenmod soap && systemctl restart php8.3-fpm
   ```

2. **Check ISPConfig SOAP API is reachable:**
   ```bash
   curl -k https://YOUR.PANEL.FQDN:PORT/remote/index.php
   ```

3. **Check Nextcloud logs:**
   ```bash
   sudo -u www-data php occ log:tail 20
   ```

### "User backend not found" in logs

Your `config.php` still uses the old class name. Change it:
```php
'class' => 'OCA\UserISPConfig\UserISPCONFIG',
```

### App auto-disables after enabling

Usually caused by a PHP fatal error. Test with:
```bash
sudo -u www-data php -d display_errors=1 occ app:enable user_ispconfig 2>&1
```

---

## How it works

1. User submits login credentials at `cloud.yourdomain.com`
2. Nextcloud calls `checkPassword($login, $password)` on all registered backends
3. This plugin connects to ISPConfig's SOAP API and calls `mail_user_get`
4. If a matching user is found, the stored crypt hash is verified against the
   submitted password using PHP's `crypt()` function
5. On success, the plugin returns the mapped UID to Nextcloud
6. Nextcloud creates the user account (if new) and starts the session

---

## License

AGPL-3.0-or-later — same as the original plugin.
