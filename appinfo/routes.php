<?php

declare(strict_types=1);

/**
 * Routes for the UPM app. Route names become "upm.<name>".
 *
 * Data routes keep the default CSRF protection; only the HTML page disables it
 * (the user may open it directly by URL, before any CSRF token exists).
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'db#config', 'url' => '/config', 'verb' => 'GET'],

		// Multiple databases: discovery + access by Nextcloud file id.
		['name' => 'db#listDatabases', 'url' => '/databases', 'verb' => 'GET'],
		['name' => 'db#createDatabase', 'url' => '/databases', 'verb' => 'POST'],
		['name' => 'db#lookup', 'url' => '/databases/lookup', 'verb' => 'GET'],
		['name' => 'db#getFile', 'url' => '/databases/{fileId}', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],
		['name' => 'db#putFile', 'url' => '/databases/{fileId}', 'verb' => 'PUT', 'requirements' => ['fileId' => '\d+']],
		['name' => 'db#deleteFile', 'url' => '/databases/{fileId}', 'verb' => 'DELETE', 'requirements' => ['fileId' => '\d+']],

		// Legacy single-database routes (default upm/upm.db).
		['name' => 'db#get', 'url' => '/db', 'verb' => 'GET'],
		['name' => 'db#put', 'url' => '/db', 'verb' => 'PUT'],
		['name' => 'db#delete', 'url' => '/db', 'verb' => 'DELETE'],
	],
];
