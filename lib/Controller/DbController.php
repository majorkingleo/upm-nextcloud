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
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
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
		$default = null;
		$userFolder = $this->userFolder();
		if ($userFolder !== null) {
			$node = $this->resolvePath($this->folder() . '/' . self::DB_FILE);
			if ($node instanceof File) {
				$default = $this->describe($node, $userFolder);
			}
		}

		return new DataResponse([
			'userId' => $this->userSession->getUser()?->getUID(),
			'folder' => $this->folder(),
			'fileName' => self::DB_FILE,
			'defaultFolder' => $this->folder(),
			'defaultDatabase' => $default,
		]);
	}

	/**
	 * List the `*.db` files in the configured root folder.
	 */
	#[NoAdminRequired]
	public function listDatabases(): DataResponse {
		$userFolder = $this->userFolder();
		if ($userFolder === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$folder = $this->resolvePath($this->folder());
		if (!$folder instanceof Folder) {
			return new DataResponse([]);
		}

		$databases = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && $this->isDatabaseName($node->getName())) {
				$databases[] = $this->describe($node, $userFolder);
			}
		}
		usort($databases, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

		return new DataResponse($databases);
	}

	/**
	 * Describe one database by its path inside the user's files.
	 */
	#[NoAdminRequired]
	public function lookup(): DataResponse {
		$userFolder = $this->userFolder();
		if ($userFolder === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$path = $this->cleanDatabasePath((string) $this->request->getParam('path', ''));
		if ($path === null) {
			return new DataResponse(['error' => 'invalid path'], Http::STATUS_BAD_REQUEST);
		}

		$node = $this->resolvePath($path);
		if (!$node instanceof File) {
			return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse($this->describe($node, $userFolder));
	}

	/**
	 * Create a database file at `path` from the raw request body.
	 */
	#[NoAdminRequired]
	public function createDatabase(): DataResponse {
		$userFolder = $this->userFolder();
		if ($userFolder === null) {
			return new DataResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$path = $this->cleanDatabasePath((string) $this->request->getParam('path', ''));
		if ($path === null) {
			return new DataResponse(['error' => 'invalid path'], Http::STATUS_BAD_REQUEST);
		}
		if ($userFolder->nodeExists($path)) {
			return new DataResponse(['error' => 'already exists'], Http::STATUS_CONFLICT);
		}

		$content = $this->readRawBody();
		if ($content === '') {
			return new DataResponse(['error' => 'empty body'], Http::STATUS_BAD_REQUEST);
		}

		$parent = $this->ensureParentFolder($userFolder, $path);
		if ($parent === null) {
			return new DataResponse(['error' => 'invalid path'], Http::STATUS_BAD_REQUEST);
		}

		$file = $parent->newFile(basename($path), $content);

		return new DataResponse($this->describe($file, $userFolder), Http::STATUS_CREATED);
	}

	/**
	 * Return a database by its Nextcloud file id, or 404 when it is gone.
	 */
	#[NoAdminRequired]
	public function getFile(int $fileId): Response {
		$node = $this->findById($fileId);
		if (!$node instanceof File) {
			return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataDisplayResponse(
			$node->getContent(),
			Http::STATUS_OK,
			['Content-Type' => 'application/octet-stream'],
		);
	}

	/**
	 * Overwrite a database by its Nextcloud file id.
	 */
	#[NoAdminRequired]
	public function putFile(int $fileId): DataResponse {
		$node = $this->findById($fileId);
		if ($node === null) {
			return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
		}
		if (!$node instanceof File) {
			return new DataResponse(['error' => 'not a file'], Http::STATUS_BAD_REQUEST);
		}
		if (!$node->isUpdateable()) {
			return new DataResponse(['error' => 'read only'], Http::STATUS_FORBIDDEN);
		}

		$content = $this->readRawBody();
		if ($content === '') {
			return new DataResponse(['error' => 'empty body'], Http::STATUS_BAD_REQUEST);
		}

		$node->putContent($content);

		return new DataResponse(['ok' => true, 'fileId' => $fileId]);
	}

	/**
	 * Delete a database by its Nextcloud file id (a missing file is fine).
	 */
	#[NoAdminRequired]
	public function deleteFile(int $fileId): DataResponse {
		$node = $this->findById($fileId);
		if ($node !== null) {
			$node->delete();
		}

		return new DataResponse(['ok' => true]);
	}

	/**
	 * Return the raw encrypted database file, or 404 when there is none yet.
	 *
	 * @deprecated use {@see getFile()} with a file id
	 */
	#[NoAdminRequired]
	public function get(): Response {
		$file = $this->resolvePath($this->folder() . '/' . self::DB_FILE);
		if (!$file instanceof File) {
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
	 *
	 * @deprecated use {@see putFile()} with a file id
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

		$path = $this->folder() . '/' . self::DB_FILE;
		$file = $this->writeDatabase($userFolder, $path, $content);
		if ($file === null) {
			return new DataResponse(['error' => 'cannot write'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['ok' => true, 'fileId' => $file->getId()]);
	}

	/**
	 * Delete the stored database. A missing file counts as success.
	 *
	 * @deprecated use {@see deleteFile()} with a file id
	 */
	#[NoAdminRequired]
	public function delete(): DataResponse {
		$file = $this->resolvePath($this->folder() . '/' . self::DB_FILE);
		if ($file instanceof File) {
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
	 * Resolve a path (relative to the user's files) to a node, or null.
	 */
	private function resolvePath(string $path): ?Node {
		$userFolder = $this->userFolder();
		$clean = $this->cleanRelativePath($path);
		if ($userFolder === null || $clean === null) {
			return null;
		}
		try {
			return $userFolder->get($clean);
		} catch (NotFoundException | NotPermittedException) {
			return null;
		}
	}

	/**
	 * Resolve a node by its Nextcloud file id, ensuring it is inside the
	 * current user's files.
	 */
	private function findById(int $fileId): ?Node {
		$userFolder = $this->userFolder();
		if ($userFolder === null) {
			return null;
		}
		$nodes = $userFolder->getById($fileId);
		if ($nodes === []) {
			return null;
		}
		$node = $nodes[0];
		if ($userFolder->getRelativePath($node->getPath()) === null) {
			return null;
		}
		return $node;
	}

	/**
	 * Write database bytes at `path`, creating parent folders as needed.
	 */
	private function writeDatabase(Folder $userFolder, string $path, string $content): ?File {
		$parent = $this->ensureParentFolder($userFolder, $path);
		if ($parent === null) {
			return null;
		}

		$name = basename($path);
		if ($parent->nodeExists($name)) {
			$node = $parent->get($name);
			if (!$node instanceof File || !$node->isUpdateable()) {
				return null;
			}
			$node->putContent($content);
			return $node;
		}

		return $parent->newFile($name, $content);
	}

	/**
	 * Ensure every parent folder of `path` exists; null when a segment is a
	 * file instead of a folder.
	 */
	private function ensureParentFolder(Folder $userFolder, string $path): ?Folder {
		$segments = explode('/', $path);
		array_pop($segments);

		$current = $userFolder;
		foreach ($segments as $segment) {
			if ($current->nodeExists($segment)) {
				$child = $current->get($segment);
				if (!$child instanceof Folder) {
					return null;
				}
				$current = $child;
			} else {
				$current = $current->newFolder($segment);
			}
		}

		return $current;
	}

	/**
	 * Describe a file for the frontend (no secrets; paths are user-relative).
	 */
	private function describe(Node $node, Folder $userFolder): array {
		return [
			'fileId' => $node->getId(),
			'path' => $userFolder->getRelativePath($node->getPath()) ?? $node->getName(),
			'name' => $node->getName(),
			'size' => $node->getSize(),
			'mtime' => $node->getMTime(),
			'canWrite' => $node->isUpdateable(),
		];
	}

	/**
	 * Trim a user-relative path and reject traversal/absolute/empty segments.
	 */
	private function cleanRelativePath(string $path): ?string {
		$path = str_replace('\\', '/', trim($path));
		$path = trim($path, '/');
		if ($path === '' || strlen($path) > 400 || str_contains($path, "\0")) {
			return null;
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return null;
			}
		}
		return $path;
	}

	/**
	 * Like {@see cleanRelativePath()}, but only accepts `*.db` names.
	 */
	private function cleanDatabasePath(string $path): ?string {
		$clean = $this->cleanRelativePath($path);
		if ($clean === null || !$this->isDatabaseName($clean)) {
			return null;
		}
		return $clean;
	}

	/** True for names ending in `.db` (case-insensitive). */
	private function isDatabaseName(string $name): bool {
		return str_ends_with(strtolower($name), '.db');
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
