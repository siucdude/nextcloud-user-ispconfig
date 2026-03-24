<?php
declare(strict_types=1);
namespace OCA\UserISPConfig;
use OCP\IUserBackend;
use OCP\UserInterface;

/**
 * ISPConfig User Backend for Nextcloud 28–33
 *
 * Authenticates Nextcloud users against the ISPConfig mail user API via SOAP.
 *
 * Compatibility notes:
 *   - NC32 BREAKING CHANGE: checkPassword() now receives the raw login string
 *     (email address) directly instead of a pre-resolved UID. Both cases are
 *     handled here so this works on NC28–33.
 *   - Implements only public OCP interfaces (no private \OC\User\Backend).
 *   - Interface methods have no type hints to match OCP\UserInterface which is
 *     untyped — adding types would cause PHP fatal errors.
 *   - Uses oc_users_ispconfig table (the original plugin's table) for user
 *     existence checks, preserving all existing user data.
 *
 * config.php setup:
 *   IMPORTANT: Use the full namespaced class name, not the legacy alias:
 *   'class' => 'OCA\UserISPConfig\UserISPCONFIG'   <-- correct
 *   'class' => 'OC_User_ISPCONFIG'                  <-- will NOT work on NC31+
 */
class UserISPCONFIG implements IUserBackend, UserInterface {

    public const CHECK_PASSWORD = 256;

    private $soapLocation;
    private $soapUri;
    private $soapUser;
    private $soapPassword;
    private $options;

    public function __construct($soapLocation, $soapUri, $soapUser, $soapPassword, $options = []) {
        $this->soapLocation = $soapLocation;
        $this->soapUri      = $soapUri;
        $this->soapUser     = $soapUser;
        $this->soapPassword = $soapPassword;
        $this->options      = $options;
        if (!isset($this->options['map_uids'])) {
            $this->options['map_uids'] = true;
        }
    }

    // -------------------------------------------------------------------------
    // IUserBackend + UserInterface — required methods
    // -------------------------------------------------------------------------

    public function getBackendName() { return 'ISPConfig'; }

    // No type hints on interface methods — OCP\UserInterface is untyped and PHP
    // will fatal if implementing class adds stricter types than the interface.
    public function implementsActions($actions) { return (bool)(self::CHECK_PASSWORD & $actions); }
    public function hasUserListings() { return false; }
    public function deleteUser($uid) { return false; }
    public function getDisplayName($uid) { return $uid; }
    public function getDisplayNames($search = '', $limit = null, $offset = null) { return []; }
    public function getUsers($search = '', $limit = null, $offset = null) { return []; }
    public function countUsers() { return false; }

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

    // -------------------------------------------------------------------------
    // checkPassword — main authentication method
    //
    // NC32 change: Nextcloud 32+ passes the raw login string (usually an email
    // address) directly here, instead of first resolving it to a mapped UID.
    // We detect which case we're in based on whether the string contains '@'.
    // -------------------------------------------------------------------------

    public function checkPassword($loginName, $password) {
        if (!extension_loaded('soap')) {
            $this->logError('PHP soap extension is not installed or not enabled');
            return false;
        }

        $mapUids = (bool)($this->options['map_uids'] ?? true);
        $isEmail = str_contains($loginName, '@');

        if ($isEmail) {
            // NC32+ path: loginName is the raw email — use directly as SOAP login
            $soapLogin = $loginName;
        } elseif ($mapUids) {
            // NC<=31 path: loginName is a mapped UID — reverse to get the email
            $reversed  = $this->reverseMappedUidToEmail($loginName);
            $soapLogin = ($reversed !== false) ? $reversed : $loginName;
        } else {
            // map_uids=false: loginName IS the ISPConfig login
            $soapLogin = $loginName;
        }

        try {
            $client = $this->getSoapClient();
            if ($client === false) return false;

            $session = $client->login($this->soapUser, $this->soapPassword);
            if (empty($session)) {
                $this->logError('SOAP login to ISPConfig failed — check remote API credentials');
                return false;
            }

            $mailUsers = $client->mail_user_get($session, ['login' => $soapLogin]);
            $client->logout($session);

            if (empty($mailUsers) || !is_array($mailUsers)) return false;

            foreach ($mailUsers as $mailUser) {
                $ispLogin = $mailUser['login'] ?? '';
                if (empty($ispLogin)) continue;
                if (!$this->isDomainAllowed($ispLogin)) continue;
                if (!$this->verifyPassword($password, $mailUser)) continue;

                // Auth succeeded — derive NC UID from confirmed ISPConfig email
                $uid = $mapUids ? $this->mapEmailToUid($ispLogin) : $ispLogin;

                // Record user in oc_users_ispconfig (creates row on first login)
                $this->storeUser($uid);

                // Apply quota, groups, email, preferences — non-fatal if it fails
                try { $this->applyUserSettings($uid, $mailUser); }
                catch (\Throwable $e) { $this->logError('applyUserSettings: ' . $e->getMessage()); }

                return $uid;
            }
        } catch (\Throwable $e) {
            $this->logError('SOAP exception: ' . $e->getMessage());
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Record the UID in oc_users_ispconfig on first login.
     * This table was created by the original plugin and is preserved here
     * to maintain backwards compatibility with existing user data.
     */
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

    private function getSoapClient() {
        try {
            return new \SoapClient(null, [
                'location'       => $this->soapLocation,
                'uri'            => $this->soapUri,
                'trace'          => 0,
                'exceptions'     => true,
                'stream_context' => stream_context_create([
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('SOAP client error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Map ISPConfig email address to a Nextcloud UID using domain_config rules.
     * Order: bare-name > uid-prefix > uid-suffix > full email address.
     */
    private function mapEmailToUid($email) {
        [$mailbox, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $cfg = $this->options['domain_config'][$domain] ?? [];
        if (!empty($cfg['bare-name']))  return $mailbox;
        if (!empty($cfg['uid-prefix'])) return $cfg['uid-prefix'] . $mailbox;
        if (!empty($cfg['uid-suffix'])) return $mailbox . $cfg['uid-suffix'];
        return $email;
    }

    /**
     * Reverse-map a Nextcloud UID back to an ISPConfig email address.
     * Used on NC<=31 where checkPassword() receives the mapped UID.
     */
    private function reverseMappedUidToEmail($uid) {
        foreach ($this->options['domain_config'] ?? [] as $domain => $cfg) {
            if (!empty($cfg['bare-name']) && !str_contains($uid, '@'))
                return $uid . '@' . $domain;
            if (!empty($cfg['uid-prefix']) && str_starts_with($uid, (string)$cfg['uid-prefix']))
                return substr($uid, strlen((string)$cfg['uid-prefix'])) . '@' . $domain;
            if (!empty($cfg['uid-suffix']) && str_ends_with($uid, (string)$cfg['uid-suffix']))
                return substr($uid, 0, -strlen((string)$cfg['uid-suffix'])) . '@' . $domain;
        }
        return str_contains($uid, '@') ? $uid : false;
    }

    /**
     * Check whether the login domain is in the allowed_domains whitelist.
     */
    private function isDomainAllowed($login) {
        $allowed = $this->options['allowed_domains'] ?? false;
        if (empty($allowed)) return true;
        [, $domain] = array_pad(explode('@', $login, 2), 2, '');
        return in_array($domain, (array)$allowed, true);
    }

    /**
     * Verify a plain-text password against ISPConfig's stored crypt hash.
     * Supports DES, MD5-CRYPT ($1$), SHA-256 ($5$), SHA-512 ($6$).
     */
    private function verifyPassword($password, $mailUser) {
        $stored = $mailUser['password'] ?? '';
        if (empty($stored)) return false;
        return hash_equals($stored, crypt($password, $stored));
    }

    /**
     * Apply quota, groups, email address and app preferences after login.
     * Runs on every login but is safe to call repeatedly (idempotent).
     */
    private function applyUserSettings($uid, $mailUser) {
        [$mailbox, $domain] = array_pad(explode('@', $mailUser['login'] ?? '', 2), 2, '');
        $domainCfg   = $this->options['domain_config'][$domain] ?? [];
        $quota       = $domainCfg['quota'] ?? $this->options['default_quota'] ?? null;
        $groups      = array_unique(array_merge(
            (array)($this->options['default_groups'] ?? []),
            (array)($domainCfg['groups'] ?? [])
        ));
        $preferences = array_merge_recursive(
            (array)($this->options['preferences'] ?? []),
            (array)($domainCfg['preferences'] ?? [])
        );

        $userManager  = \OC::$server->getUserManager();
        $groupManager = \OC::$server->getGroupManager();
        $config       = \OC::$server->getConfig();
        $user         = $userManager->get($uid);
        if ($user === null) return;

        if ($quota !== null) $user->setQuota((string)$quota);
        if (empty($user->getEMailAddress()) && !empty($mailUser['login']))
            $user->setEMailAddress($mailUser['login']);
        if ($user->getDisplayName() === $uid && !empty($mailUser['login']))
            $user->setDisplayName($mailUser['login']);

        foreach ($groups as $groupName) {
            if (empty($groupName)) continue;
            if (!$groupManager->groupExists($groupName)) $groupManager->createGroup($groupName);
            $group = $groupManager->get($groupName);
            if ($group && !$group->inGroup($user)) $group->addUser($user);
        }

        foreach ($preferences as $appId => $appPrefs) {
            foreach ((array)$appPrefs as $key => $value) {
                $value = str_replace(
                    ['%UID%', '%MAILBOX%', '%DOMAIN%'],
                    [$uid, $mailbox, $domain],
                    (string)$value
                );
                $config->setUserValue($uid, (string)$appId, (string)$key, $value);
            }
        }
    }

    private function logError($message) {
        try {
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)
                ->error('[UserISPConfig] ' . $message);
        } catch (\Throwable $t) {}
    }
}

// Legacy alias kept for reference — DO NOT use in config.php on NC31+
// Use 'class' => 'OCA\UserISPConfig\UserISPCONFIG' instead.
class_alias(UserISPCONFIG::class, 'OC_User_ISPCONFIG');
