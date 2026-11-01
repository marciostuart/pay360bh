-- Apply only to the dedicated central_cobrancas_sandbox database.
-- Source tables are copied from the existing NocoDB PostgreSQL database first.
BEGIN;

CREATE SCHEMA IF NOT EXISTS pvfbh8yqws2olzw;

ALTER TABLE pvfbh8yqws2olzw."Projetos SANDBOX"
    ADD COLUMN IF NOT EXISTS api_key_hash text,
    ADD COLUMN IF NOT EXISTS ativo boolean NOT NULL DEFAULT true;

ALTER TABLE pvfbh8yqws2olzw."Cobrancas SANDBOX"
    ADD COLUMN IF NOT EXISTS cliente_local_id integer,
    ADD COLUMN IF NOT EXISTS projeto_id integer,
    ADD COLUMN IF NOT EXISTS descricao text,
    ADD COLUMN IF NOT EXISTS invoice_url text,
    ADD COLUMN IF NOT EXISTS pix_payload text,
    ADD COLUMN IF NOT EXISTS pix_encoded_image text,
    ADD COLUMN IF NOT EXISTS origem text;

CREATE UNIQUE INDEX IF NOT EXISTS central_projetos_api_key_hash_uidx
    ON pvfbh8yqws2olzw."Projetos SANDBOX" (api_key_hash)
    WHERE api_key_hash IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS central_clientes_documento_normalizado_uidx
    ON pvfbh8yqws2olzw."Clientes MDI" (
        (NULLIF(regexp_replace(
            COALESCE(NULLIF(btrim(cpf), ''), cnpj, ''),
            '[^0-9]', '', 'g'
        ), ''))
    )
    WHERE NULLIF(regexp_replace(
        COALESCE(NULLIF(btrim(cpf), ''), cnpj, ''),
        '[^0-9]', '', 'g'
    ), '') IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS central_clientes_asaas_id_uidx
    ON pvfbh8yqws2olzw."Clientes MDI" (asaas_id)
    WHERE NULLIF(btrim(asaas_id), '') IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS central_cobrancas_asaas_id_uidx
    ON pvfbh8yqws2olzw."Cobrancas SANDBOX" (cobranca_asaas_id)
    WHERE NULLIF(btrim(cobranca_asaas_id), '') IS NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'central_cobrancas_cliente_local_fk'
          AND connamespace = 'pvfbh8yqws2olzw'::regnamespace
    ) THEN
        ALTER TABLE pvfbh8yqws2olzw."Cobrancas SANDBOX"
            ADD CONSTRAINT central_cobrancas_cliente_local_fk
            FOREIGN KEY (cliente_local_id)
            REFERENCES pvfbh8yqws2olzw."Clientes MDI" (id)
            ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'central_cobrancas_projeto_fk'
          AND connamespace = 'pvfbh8yqws2olzw'::regnamespace
    ) THEN
        ALTER TABLE pvfbh8yqws2olzw."Cobrancas SANDBOX"
            ADD CONSTRAINT central_cobrancas_projeto_fk
            FOREIGN KEY (projeto_id)
            REFERENCES pvfbh8yqws2olzw."Projetos SANDBOX" (id)
            ON DELETE RESTRICT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS central_cobrancas_cliente_idx
    ON pvfbh8yqws2olzw."Cobrancas SANDBOX" (cliente_local_id);
CREATE INDEX IF NOT EXISTS central_cobrancas_projeto_idx
    ON pvfbh8yqws2olzw."Cobrancas SANDBOX" (projeto_id);
CREATE INDEX IF NOT EXISTS central_cobrancas_status_idx
    ON pvfbh8yqws2olzw."Cobrancas SANDBOX" (status);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'central_pay_data') THEN
        CREATE ROLE central_pay_data NOLOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'central_n8n_data') THEN
        CREATE ROLE central_n8n_data NOLOGIN;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'central_nocodb_data') THEN
        CREATE ROLE central_nocodb_data NOLOGIN;
    END IF;
END $$;

GRANT USAGE ON SCHEMA pvfbh8yqws2olzw
    TO central_pay_data, central_n8n_data, central_nocodb_data;

GRANT SELECT ON ALL TABLES IN SCHEMA pvfbh8yqws2olzw
    TO central_pay_data;
GRANT INSERT, UPDATE ON pvfbh8yqws2olzw."Clientes MDI",
    pvfbh8yqws2olzw."Projetos SANDBOX"
    TO central_pay_data;
GRANT UPDATE (cliente_local_id, projeto_id, descricao, invoice_url,
    pix_payload, pix_encoded_image, origem)
    ON pvfbh8yqws2olzw."Cobrancas SANDBOX" TO central_pay_data;

GRANT SELECT, INSERT, UPDATE ON ALL TABLES IN SCHEMA pvfbh8yqws2olzw
    TO central_n8n_data, central_nocodb_data;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA pvfbh8yqws2olzw
    TO central_pay_data, central_n8n_data, central_nocodb_data;

COMMIT;
