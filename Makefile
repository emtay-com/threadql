.PHONY: default

DOCKER = docker
DOCKER_COMPOSE = docker compose

APP_EXEC = $(DOCKER) exec -it threadql
WORKER_EXEC = $(DOCKER_COMPOSE) exec -it worker

default:
	@echo "Available makefile targets:"
	@grep -hE '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "  %s: %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

bash: ## run shell
	${APP_EXEC} bash

sh: bash
sh: bash

tinker: ## run laravel tinker
	${APP_EXEC} bash -c "php artisan tinker"

queue: ## run laravel queue
	${APP_EXEC} bash -c "php artisan queue\:listen"

install-composer:
	${APP_EXEC} bash -c "php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\" && php composer-setup.php --install-dir=/usr/local/bin --filename=composer && php -r \"unlink('composer-setup.php');\""

test: ## run tests
	${APP_EXEC} bash -c "./run-tests.sh"

up: ## docker compose up
	${DOCKER_COMPOSE} up -d

down: ## docker compose down
	${DOCKER_COMPOSE} down --remove-orphans

restart-worker: ## restart worker
	${DOCKER_COMPOSE} restart worker

ecsfix: ## ecs fix
	${APP_EXEC} bash -c "./vendor/bin/ecs --fix"

stan: ## run phpstan
	${APP_EXEC} bash -c "./vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=1G"

migrate: ## run migrations
	${APP_EXEC} bash -c "php artisan migrate"

config-cache: ## set config-cache
	${APP_EXEC} bash -c "php artisan config:cache"

clear-relay-cache: ## Clear Relay tool definition cache from Redis
	@docker exec threadql-redis redis-cli DEL relay-tools-definitions-app > /dev/null 2>&1 || true

reload: config-cache clear-relay-cache restart-worker restart-ssh-tunnel ## reload after updating code or after running tests

log-tail: ## tail laravel log
	${APP_EXEC} bash -c "tail -f storage/logs/laravel.log"

worker-tail:
	${WORKER_EXEC} bash -c "tail -f /var/log/worker.log"

recent-logs: ## recent laravel logs
	${APP_EXEC} bash -c "tail -n 250 storage/logs/laravel.log"

restart-ssh-tunnel: ## restart SSH tunnel manager
	${DOCKER_COMPOSE} restart ssh-tunnel

ssh-tunnels: ## List active SSH tunnels
	docker compose exec threadql curl -s http://threadql-ssh-tunnel:8092/tunnels | python3 -m json.tool

## —— Redis 🧰  ———————————————————————————————————————————————————————————————
list-redis: ## List available cache keys in redis
	docker exec -it threadql-redis sh -c "redis-cli KEYS '*'"

clear-redis: ## Clear up redis as a whole
	docker exec -it threadql-redis sh -c "redis-cli FLUSHALL"

dump-redis: ## Dump raw output of redis to the console
	@docker exec threadql-redis sh -c "redis-cli KEYS '*' | while read -r key; do type=\$$(redis-cli TYPE \"\$$key\"); ttl=\$$(redis-cli TTL \"\$$key\"); value=\$$(redis-cli GET \"\$$key\"); echo \"key=\$$key type=\$$type ttl=\$$ttl value=\$$value\"; done"

sync-permissions: ## sync permissions
	sudo find . -type d -exec chmod 755 {} \;

cloudflared: ## start cloudflare tunnel to local machine
	cloudflared tunnel --loglevel debug  run --token ${CLOUDFLARE_TUNNEL_TOKEN}

generate-key: ## generate app key
	docker run -it emtaycom/threadql bash -c "php artisan key:generate --show"

generate-jwtsecret: ## generate jwt secret
	docker run -it emtaycom/threadql bash -c "php artisan jwt:secret --show"

start-postgres: ## start postgres container for local development
	@docker volume inspect postgres-data >/dev/null 2>&1 || docker volume create postgres-data
	@if docker inspect postgres-db >/dev/null 2>&1; then \
		echo "✅ Container already exists – starting it"; \
		docker start postgres-db; \
	else \
		echo "🚀 Creating and starting new container"; \
		docker run \
		  --name postgres-db \
		  -v posgres-data:/var/lib/postgresql \
		  -v $$(pwd)/stubs:/root \
		  -e POSTGRES_PASSWORD=mysecretpassword \
		  -p 5432:5432 \
		  -d \
		  postgres \
		  -c listen_addresses='*'; \
	fi

stop-postgres: ## stop postgres
	@docker stop postgres-db >/dev/null 2>&1 || echo "No running container to stop"
