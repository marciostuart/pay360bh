#!/usr/bin/env bash
set -euo pipefail

container="${POSTGRES_CONTAINER:-postgres-postgres-1}"
source_db="central_cobrancas_sandbox"
target_db="central_cobrancas_prod"
secret_dir="/opt/360bh/secrets"
pay_secret="${secret_dir}/pay360bh_prod_db_password"
n8n_secret="${secret_dir}/central_cobrancas_prod_n8n_password"
mode="${1:-}"
if [[ -n "$mode" && "$mode" != "--dry-run" ]]; then
    echo "Uso: $0 [--dry-run]" >&2
    exit 1
fi

for command in docker openssl sed mktemp grep install; do
    command -v "$command" >/dev/null || { echo "Comando ausente: $command" >&2; exit 1; }
done

if [[ -e "$pay_secret" || -e "$n8n_secret" ]]; then
    echo "Arquivo de credencial de produção já existe; verifique o estado antes de continuar." >&2
    exit 1
fi
existing="$(docker exec "$container" psql -U postgres -d postgres -Atqc "SELECT (EXISTS (SELECT 1 FROM pg_database WHERE datname='central_cobrancas_prod') OR EXISTS (SELECT 1 FROM pg_roles WHERE rolname IN ('pay360bh_prod_login','central_cobrancas_prod_n8n')))::int")"
if [[ "$existing" != "0" ]]; then
    echo "O banco ou uma das contas de produção já existe; nenhuma alteração foi feita." >&2
    exit 1
fi

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT
trap 'echo "A preparação parou. Verifique o banco, os logins e os arquivos de credenciais antes de repetir." >&2' ERR
docker exec "$container" pg_dump -U postgres -d "$source_db" --schema-only --no-owner --no-privileges --strict-names \
    --table='pvfbh8yqws2olzw."Projetos SANDBOX"' \
    --table='pvfbh8yqws2olzw."Clientes MDI"' \
    --table='pvfbh8yqws2olzw."Cobrancas SANDBOX"' \
    --table='pvfbh8yqws2olzw."Eventos Asaas SANDBOX"' \
    --table='pvfbh8yqws2olzw."Entregas SANDBOX"' > "$workdir/schema.sql"
for table in 'Projetos SANDBOX' 'Clientes MDI' 'Cobrancas SANDBOX' 'Eventos Asaas SANDBOX' 'Entregas SANDBOX'; do
    if ! grep -Fq "$table" "$workdir/schema.sql"; then
        echo "A estrutura esperada não foi encontrada: $table. Nenhuma alteração foi feita." >&2
        exit 1
    fi
done
sed -e 's/pvfbh8yqws2olzw/central_cobrancas/g' \
    -e 's/Projetos SANDBOX/Projetos/g' \
    -e 's/Cobrancas SANDBOX/Cobrancas/g' \
    -e 's/Eventos Asaas SANDBOX/Eventos Asaas/g' \
    -e 's/Entregas SANDBOX/Entregas/g' \
    -e 's/sandbox/producao/g' \
    -e 's/SANDBOX/PRODUCAO/g' "$workdir/schema.sql" > "$workdir/production.sql"
if grep -iq 'sandbox' "$workdir/production.sql"; then
    echo "A estrutura contém referências de homologação que exigem revisão. Nenhuma alteração foi feita." >&2
    exit 1
fi

if [[ "$mode" == "--dry-run" ]]; then
    echo "Estrutura obtida sem copiar dados. Prévia das tabelas:"
    grep '^CREATE TABLE ' "$workdir/production.sql"
    echo "Prévia concluída; nenhum banco ou arquivo de credencial foi criado."
    exit 0
fi

if [[ ! -d "$secret_dir" ]]; then
    install -d -m 0750 -o root -g 33 "$secret_dir"
fi
umask 077
openssl rand -hex 48 > "$pay_secret"
openssl rand -hex 48 > "$n8n_secret"
chown root:33 "$pay_secret"
chmod 0440 "$pay_secret"
chown root:root "$n8n_secret"
chmod 0600 "$n8n_secret"

docker exec "$container" psql -v ON_ERROR_STOP=1 -U postgres -d postgres -c 'CREATE DATABASE central_cobrancas_prod TEMPLATE template0'
docker exec "$container" psql -v ON_ERROR_STOP=1 -U postgres -d postgres -c 'REVOKE ALL ON DATABASE central_cobrancas_prod FROM PUBLIC'
docker exec "$container" psql -v ON_ERROR_STOP=1 -U postgres -d "$target_db" -c 'CREATE SCHEMA central_cobrancas'
docker exec -i "$container" psql -v ON_ERROR_STOP=1 -1 -U postgres -d "$target_db" < "$workdir/production.sql"

{
    printf "\\set pay_password '%s'\n" "$(cat "$pay_secret")"
    printf "\\set n8n_password '%s'\n" "$(cat "$n8n_secret")"
    cat <<'SQL'
CREATE ROLE pay360bh_prod_login LOGIN PASSWORD :'pay_password';
CREATE ROLE central_cobrancas_prod_n8n LOGIN PASSWORD :'n8n_password';
GRANT CONNECT ON DATABASE central_cobrancas_prod TO pay360bh_prod_login, central_cobrancas_prod_n8n;
REVOKE ALL ON SCHEMA central_cobrancas FROM PUBLIC;
GRANT USAGE ON SCHEMA central_cobrancas TO pay360bh_prod_login, central_cobrancas_prod_n8n;
GRANT SELECT ON ALL TABLES IN SCHEMA central_cobrancas TO pay360bh_prod_login;
GRANT INSERT, UPDATE ON central_cobrancas."Clientes MDI", central_cobrancas."Projetos" TO pay360bh_prod_login;
GRANT UPDATE (cliente_local_id, projeto_id, descricao, invoice_url, pix_payload, pix_encoded_image, origem)
    ON central_cobrancas."Cobrancas" TO pay360bh_prod_login;
GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA central_cobrancas TO central_cobrancas_prod_n8n;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA central_cobrancas TO pay360bh_prod_login, central_cobrancas_prod_n8n;
SQL
} | docker exec -i "$container" psql -v ON_ERROR_STOP=1 -1 -U postgres -d "$target_db" >/dev/null

docker exec "$container" psql -v ON_ERROR_STOP=1 -U postgres -d "$target_db" -c \
    'SELECT (SELECT count(*) FROM central_cobrancas."Projetos") AS projetos, (SELECT count(*) FROM central_cobrancas."Clientes MDI") AS clientes, (SELECT count(*) FROM central_cobrancas."Cobrancas") AS cobrancas, (SELECT count(*) FROM central_cobrancas."Eventos Asaas") AS eventos, (SELECT count(*) FROM central_cobrancas."Entregas") AS entregas;'
echo "Estrutura de produção criada. As credenciais foram guardadas em arquivos protegidos no VPS."
echo "Se alguma etapa falhou, não repita às cegas: verifique o banco, as contas e os arquivos antes de continuar."
