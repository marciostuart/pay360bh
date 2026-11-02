#!/usr/bin/env bash
set -euo pipefail

project_id="${1:?informe o ID do projeto pessoal no n8n}"
mode="${2:-}"
container="${N8N_EDITOR_CONTAINER:-n8n-n8n_editor-1}"
secret_dir="/opt/360bh/secrets"
db_secret="${secret_dir}/central_cobrancas_prod_n8n_password"
internal_secret="${secret_dir}/pay360bh_prod_n8n_token"
webhook_secret="${secret_dir}/asaas_prod_webhook_token"

[[ "$project_id" =~ ^[a-zA-Z0-9]+$ ]] || { echo "ID de projeto inválido." >&2; exit 1; }
[[ -r "$db_secret" ]] || { echo "Credencial do banco de produção não encontrada." >&2; exit 1; }
if [[ -z "$mode" ]]; then
    if [[ -e "$internal_secret" || -e "$webhook_secret" ]]; then
        echo "Um arquivo de token de produção já existe; verifique antes de repetir." >&2
        exit 1
    fi
    umask 077
    set -o noclobber
    openssl rand -hex 48 > "$internal_secret"
    openssl rand -hex 48 > "$webhook_secret"
    set +o noclobber
    chown root:33 "$internal_secret"
    chmod 0440 "$internal_secret"
    chown root:root "$webhook_secret"
    chmod 0600 "$webhook_secret"
elif [[ "$mode" == "--reuse-existing-tokens" ]]; then
    [[ -r "$internal_secret" && -r "$webhook_secret" ]] || { echo "Tokens existentes não encontrados." >&2; exit 1; }
else
    echo "Opção inválida." >&2
    exit 1
fi

python3 - "$db_secret" "$internal_secret" "$webhook_secret" <<'PY' | docker exec -i -u node "$container" sh -c 'set -eu; f="$(mktemp)"; trap '\''rm -f "$f"'\'' EXIT; chmod 600 "$f"; cat > "$f"; n8n import:credentials --input="$f" --projectId="$1"' sh "$project_id"
import json, pathlib, secrets, sys
db_password, internal_token, webhook_token = [pathlib.Path(p).read_text().strip() for p in sys.argv[1:]]
credentials = [
    {'id': secrets.token_hex(8), 'name': 'Central PostgreSQL Produção', 'type': 'postgres', 'data': {
        'host': 'postgres-postgres-1', 'database': 'central_cobrancas_prod',
        'user': 'central_cobrancas_prod_n8n', 'password': db_password,
        'port': 5432, 'maxConnections': 5, 'ssl': 'disable', 'allowUnauthorizedCerts': False,
    }},
    {'id': secrets.token_hex(8), 'name': 'Central Entrada Produção', 'type': 'httpHeaderAuth', 'data': {
        'name': 'x-central-token', 'value': internal_token,
    }},
    {'id': secrets.token_hex(8), 'name': 'Asaas Webhook Produção', 'type': 'httpHeaderAuth', 'data': {
        'name': 'asaas-access-token', 'value': webhook_token,
    }},
]
json.dump(credentials, sys.stdout, ensure_ascii=False)
PY

echo "Credenciais importadas no projeto solicitado; os tokens permanecem em arquivos protegidos no VPS."
