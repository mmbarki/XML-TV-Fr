
PHONY: quality
quality: cs-fix phpstan test


PHONY: cs-fix
cs-fix:
	php -d memory_limit=-1 bin/php-cs-fixer fix

PHONY: test
test:
	php -d memory_limit=-1 bin/phpunit --filter=Unit

PHONY: phpstan
phpstan:
	bin/phpstan --memory-limit=-1

PHONY: integration
integration:
	php -d memory_limit=-1 bin/phpunit --filter=Integration
drun:
	docker run -v ./makefile:/app/makefile -v ./manager.php:/app/manager.php -v ./var:/app/var -v ./config/:/app/config -v ./src:/app/src -v ./integrity.sha256:/app/integrity.sha256 -v ./resources:/app/resources -v ./commands:/app/commands -v ./tests:/app/tests xmltvfr $(ARGS)