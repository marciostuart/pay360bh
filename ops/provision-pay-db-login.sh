#!/usr/bin/env bash
set -euo pipefail

db_container="${POSTGRES_CONTAINER:-postgres-postgres-1}"
secret_dir="/opt/360bh/secrets"
secret_file="${secret_dir}/pay360bh_db_password"
database="central_cobrancas_sandbox"
login="pay360bh_login"

if ! command -v docker >/dev/null || ! command -v openssl >/dev/null; then
  echo "Este script precisa de Docker e OpenSSL no host." >&2
  exit 1
fi

mkdir -p "$secret_dir"
chown root:33 "$secret_dir"
chmod 0750 "$secret_dir"

role_exists="$(docker exec "$db_container" psql -U postgres -d "$database" -Atqc "SELECT 1 FROM pg_roles WHERE rolname='${login}'")"
if [[ "$role_exists" == "1" || -e "$secret_file" ]]; then
  echo "O login ou arquivo de senha já existe; nada foi alterado. Verifique o estado antes de repetir." >&2
  exit 1
fi

password="$(openssl rand -hex 48)"
temp_file="$(mktemp "${secret_dir}/.pay360bh_db_password.XXXXXX")"
trap 'rm -f "$temp_file"; unset password' EXIT
printf '%s' "$password" > "$temp_file"
chown root:33 "$temp_file"
chmod 0440 "$temp_file"

{
  printf "\\set pay_password '%s'\n" "$password"
  cat <<'SQL'
CREATE ROLE pay360bh_login LOGIN PASSWORD :'pay_password';
GRANT central_pay_data TO pay360bh_login;
SQL
} | docker exec -i "$db_container" psql -v ON_ERROR_STOP=1 -U postgres -d "$database" >/dev/null

mv "$temp_file" "$secret_file"
chown root:33 "$secret_file"
chmod 0440 "$secret_file"
unset password
trap - EXIT
echo "Login restrito criado; senha guardada em ${secret_file} (root:gid33, modo 0440)."
