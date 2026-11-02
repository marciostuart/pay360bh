#!/usr/bin/env bash
set -euo pipefail

image="${1:?informe a imagem de produção do Pay}"
secret_dir="/opt/360bh/secrets"
password_file="${secret_dir}/pay360bh_prod_admin_password"
hash_file="${secret_dir}/pay360bh_prod_admin_hash_b64"
if [[ -e "$password_file" || -e "$hash_file" ]]; then
    echo "Uma credencial administrativa de produção já existe; verifique antes de repetir." >&2
    exit 1
fi
[[ -d "$secret_dir" ]] || { echo "Diretório de segredos não encontrado." >&2; exit 1; }
umask 077
set -o noclobber
openssl rand -base64 32 | tr -d '\n' > "$password_file"
set +o noclobber
docker run --rm -i "$image" php -r 'echo base64_encode(password_hash(trim(stream_get_contents(STDIN)), PASSWORD_DEFAULT));' < "$password_file" > "$hash_file"
chmod 0600 "$password_file" "$hash_file"
chown root:root "$password_file" "$hash_file"
echo "Credencial administrativa criada em arquivos protegidos na VPS."
