# UPM for Nextcloud — developer tasks.

.PHONY: install lint test build validate

## Install PHP dependencies.
install:
	composer install

## Lint every PHP file in lib/.
lint:
	find lib -name '*.php' -print0 | xargs -0 -n1 php -l

## Run the TypeScript test suite.
test:
	npm test

## Build the frontend bundle into js/upm.js.
build:
	npm run build

## Validate composer.json.
validate:
	composer validate --strict
