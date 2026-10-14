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
		['name' => 'db#get', 'url' => '/db', 'verb' => 'GET'],
		['name' => 'db#put', 'url' => '/db', 'verb' => 'PUT'],
		['name' => 'db#delete', 'url' => '/db', 'verb' => 'DELETE'],
	],
];
