.PHONY: install test verify health docker-up docker-down ws

install:
	bash scripts/install.sh

test:
	vendor/bin/phpunit --colors=always

verify:
	php scripts/verify-install.php

health:
	php scripts/health-check.php

docker-up:
	docker compose -f docker/docker-compose.prod.yml up -d

docker-down:
	docker compose -f docker/docker-compose.prod.yml down

ws:
	php scripts/websocket-server.php 8081

migrate:
	php cron/run.php migrate

license:
	php scripts/generate-license.php enterprise 365
