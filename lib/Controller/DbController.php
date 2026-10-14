<?php

declare(strict_types=1);

namespace OCA\UPM\Controller;

use OCA\UPM\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Blob storage for the encrypted UPM database.
 *
 * This controller is deliberately "dumb": it never touches cryptography and
 * only stores/returns the opaque encrypted file inside the user's Nextcloud
 * files. The master password and the derived key never reach the server.
 */
class DbController extends Controller {
	/** Default folder (inside the user's files) that holds the database. */
	public const DEFAULT_FOLDER = 'upm';

	/** Name of the encrypted database file. */
	public const DB_FILE = 'upm.db';

	public function __construct(
		IRequest $request,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Report the configuration the frontend needs (no secrets).
	 */
	#[NoAdminRequired]
	public function config(): DataResponse {
		return new DataResponse([
			'userId' => $this->userSession->getUser()?->getUID(),
			'folder' => $this->folder(),
			'fileName' => self::DB_FILE,
		]);
	}

	/**
	 * Return the raw encrypted database file, or 404 when there is none yet.
	 */
	#[NoAdminRequired]
	public function get(): Response {
		$file = $this->findDbFile();
		if ($file === null) {
			return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
		}
		return new DataDisplayResponse(
			$file->getContent(),
			Http::STATUS_OK,
			['Content-Type' => 'application/octet-stream'],
		);
	}

	/**
	 * Store the raw encrypted database bytes from the request body.
	 */
	#[NoAdminRequired]
	public function put(): DataResponse {
		$userFolder = $this->userFolder();
		if ($userFolder === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$content = $this->readRawBody();
		if ($content === '') {
			return new DataResponse(['error' => 'empty body'], Http::STATUS_BAD_REQUEST);
		}

		$folder = $this->folder();
		if (!$userFolder->nodeExists($folder)) {
			$userFolder->newFolder($folder);
		}

		$path = $folder . '/' . self::DB_FILE;
		if ($userFolder->nodeExists($path)) {
			$userFolder->get($path)->putContent($content);
		} else {
			$userFolder->newFile($path, $content);
		}

		return new DataResponse(['ok' => true]);
	}

	/**
	 * Delete the stored database. A missing file counts as success.
	 */
	#[NoAdminRequired]
	public function delete(): DataResponse {
		$file = $this->findDbFile();
		if ($file !== null) {
			$file->delete();
		}
		return new DataResponse(['ok' => true]);
	}

	/**
	 * The configured folder inside the user's files (defaults to "upm").
	 *
	 * The value is admin-configurable app config, so it matches the folder the
	 * Android app syncs to over WebDAV.
	 */
	private function folder(): string {
		$raw = $this->config->getAppValue(Application::APP_ID, 'folder', self::DEFAULT_FOLDER);
		$folder = trim($raw, "/ \t\n\r\0\x0B");
		return $folder === '' ? self::DEFAULT_FOLDER : $folder;
	}

	/**
	 * The current user's files folder, or null when not logged in.
	 */
	private function userFolder(): ?Folder {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}
		return $this->rootFolder->getUserFolder($user->getUID());
	}

	/**
	 * Resolve the encrypted database file, or null when it does not exist.
	 */
	private function findDbFile(): ?File {
		$userFolder = $this->userFolder();
		if ($userFolder === null) {
			return null;
		}
		try {
			$node = $userFolder->get($this->folder() . '/' . self::DB_FILE);
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/**
	 * Read the raw request body. For a binary PUT the framework exposes the
	 * body as a stream on the `put` magic property; otherwise fall back to the
	 * PHP input stream.
	 */
	private function readRawBody(): string {
		try {
			$body = $this->request->put;
		} catch (\LogicException) {
			$body = null;
		}
		if (is_resource($body)) {
			$contents = stream_get_contents($body);
			return $contents === false ? '' : $contents;
		}
		if (is_string($body)) {
			return $body;
		}
		$contents = file_get_contents('php://input');
		return $contents === false ? '' : $contents;
	}
}
