#!/usr/bin/env bash
set -euo pipefail

container="${POSTGRES_CONTAINER:-postgres-postgres-1}"
secret_dir="/opt/360bh/secrets"
export PGPASSWORD
PGPASSWORD="$(cat "$secret_dir/pay360bh_prod_db_password")"
docker exec -e PGPASSWORD "$container" psql -v ON_ERROR_STOP=1 -h 127.0.0.1 -U pay360bh_prod_login -d central_cobrancas_prod -Atqc 'SELECT current_database(), current_user'
PGPASSWORD="$(cat "$secret_dir/central_cobrancas_prod_n8n_password")"
docker exec -e PGPASSWORD "$container" psql -v ON_ERROR_STOP=1 -h 127.0.0.1 -U central_cobrancas_prod_n8n -d central_cobrancas_prod -Atqc 'SELECT current_database(), current_user'
unset PGPASSWORD

docker exec "$container" psql -v ON_ERROR_STOP=1 -U postgres -d central_cobrancas_prod -Atqc "SELECT 'pay_pode_alterar_status=' || has_column_privilege('pay360bh_prod_login', 'central_cobrancas.\"Cobrancas\"', 'status', 'UPDATE'), 'n8n_pode_alterar_status=' || has_column_privilege('central_cobrancas_prod_n8n', 'central_cobrancas.\"Cobrancas\"', 'status', 'UPDATE'), 'tabelas_usuarios=' || (SELECT count(*) FROM information_schema.tables WHERE table_schema='central_cobrancas' AND table_name ILIKE '%usuarios%')"
docker exec "$container" psql -v ON_ERROR_STOP=1 -U postgres -d central_cobrancas_prod -c 'SELECT (SELECT count(*) FROM central_cobrancas."Projetos") AS projetos, (SELECT count(*) FROM central_cobrancas."Clientes MDI") AS clientes, (SELECT count(*) FROM central_cobrancas."Cobrancas") AS cobrancas, (SELECT count(*) FROM central_cobrancas."Eventos Asaas") AS eventos, (SELECT count(*) FROM central_cobrancas."Entregas") AS entregas;'
