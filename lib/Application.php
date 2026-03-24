<?php
declare(strict_types=1);

namespace OCA\UserISPConfig;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;

/**
 * Application bootstrap class for user_ispconfig.
 *
 * Uses the IBootstrap interface (required since NC31 — app.php is no longer
 * supported). Registers the ISPConfig user backend at boot time.
 */
class Application extends App implements IBootstrap {

    public const APP_ID = 'user_ispconfig';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Nothing to register at this stage.
    }

    public function boot(IBootContext $context): void {
        $context->injectFn([$this, 'registerBackend']);
    }

    /**
     * Read user_backends from config.php and register any ISPConfig entries.
     *
     * NOTE: The class name in config.php must be the full namespaced name:
     *   'class' => 'OCA\UserISPConfig\UserISPCONFIG'
     *
     * The legacy 'OC_User_ISPCONFIG' alias cannot be resolved by
     * OC_User::setupBackends() because class_exists() is called before
     * this app boots and the alias gets registered.
     */
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
                $args[0] ?? '',
                $args[1] ?? '',
                $args[2] ?? '',
                $args[3] ?? '',
                $args[4] ?? []
            );
            $userManager->registerBackend($instance);
        }
    }
}
