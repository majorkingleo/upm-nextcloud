<?php

declare(strict_types=1);

namespace OCA\UPM\Controller;

use OCA\UPM\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Serves the app's single-page frontend.
 */
class PageController extends Controller {
	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Render the main template that boots the bundled frontend.
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'upm');
		return new TemplateResponse(Application::APP_ID, 'index');
	}
}
