up: ## Starts docker containers and builds the application
	./vendor/bin/sail up -d
	docker-compose ps
	./vendor/bin/sail artisan horizon

watch: ## Starts docker containers and watch/rebuild on file changes
	./vendor/bin/sail bun run dev

js:
	./vendor/bin/sail bun install
	./vendor/bin/sail bun run build

down: ## Stops and removes docker containers
	./vendor/bin/sail down

destroy:
	docker-compose down --rmi all --volumes --remove-orphans
	#docker-compose down -v --remove-orphans

ssh: ## SSH into the main container
	./vendor/bin/sail shell

ssh-root: ## SSH into the main container as root
	./vendor/bin/sail root-shell

setup: ## Run setup to create ENV
	./vendor/bin/sail down
	#cp .env.example .env
	composer install
	./vendor/bin/sail up -d
	./vendor/bin/sail composer install
	./vendor/bin/sail bun install
	./vendor/bin/sail bun run build
	./vendor/bin/sail artisan key:generate
	./vendor/bin/sail artisan migrate:fresh --seed
