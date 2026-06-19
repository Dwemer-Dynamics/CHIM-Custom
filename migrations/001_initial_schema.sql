CREATE TABLE IF NOT EXISTS public.chim_custom_integrations (
    integration_id TEXT PRIMARY KEY,
    display_name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    prompt_template TEXT NOT NULL DEFAULT '',
    native_config JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS public.chim_custom_actor_state (
    actor_key TEXT NOT NULL,
    actor_name TEXT NOT NULL,
    actor_type TEXT NOT NULL,
    integration_id TEXT NOT NULL,
    state JSONB NOT NULL DEFAULT '{}'::jsonb,
    gamets BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (actor_key, integration_id)
);

CREATE INDEX IF NOT EXISTS idx_chim_custom_actor_state_integration
    ON public.chim_custom_actor_state (integration_id);

CREATE INDEX IF NOT EXISTS idx_chim_custom_actor_state_updated
    ON public.chim_custom_actor_state (updated_at DESC);

CREATE TABLE IF NOT EXISTS public.chim_custom_plugin_heartbeat (
    plugin_id TEXT PRIMARY KEY,
    plugin_version TEXT NOT NULL DEFAULT '',
    game_version TEXT NOT NULL DEFAULT '',
    last_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO public.chim_custom_integrations (
    integration_id,
    display_name,
    description,
    enabled,
    prompt_template,
    native_config
) VALUES (
    'dirt_and_blood',
    'Dirt and Blood - Dynamic Visual Effects',
    'Adds visible dirt, blood, clean, and washing state from Dirt and Blood to CHIM prompts when the mod is installed.',
    TRUE,
    '',
    '{
        "required_plugins": ["Dirt and Blood - Dynamic Visuals.esp"],
        "poll_seconds": 8,
        "forms": {
            "dirt": ["00000806", "00000807", "00000808", "00000838"],
            "blood": ["00000809", "0000080A", "0000080B", "00000839"],
            "clean": "0000080C",
            "washing": "0000081C"
        }
    }'::jsonb
) ON CONFLICT (integration_id) DO UPDATE SET
    display_name = EXCLUDED.display_name,
    description = EXCLUDED.description,
    native_config = EXCLUDED.native_config,
    updated_at = CURRENT_TIMESTAMP;
