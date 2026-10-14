<?php

declare(strict_types=1);

namespace OCA\UPM\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Application container.
 *
 * Controllers are resolved automatically by the AppFramework from the routes
 * in appinfo/routes.php, with their constructor dependencies injected, so no
 * manual service registration is needed.
 */
class Application extends App implements IBootstrap {
	/** The app id, used everywhere the framework needs it. */
	public const APP_ID = 'upm';

	/**
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
