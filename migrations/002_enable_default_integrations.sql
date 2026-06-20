DO $$
BEGIN
    IF to_regclass('plugins.chim_custom_integrations') IS NOT NULL THEN
        UPDATE plugins.chim_custom_integrations
        SET enabled = TRUE,
            updated_at = CURRENT_TIMESTAMP
        WHERE integration_id IN (
            'dirt_and_blood',
            'bathing_in_skyrim',
            'sunhelm_survival',
            'starfrost_survival'
        )
        AND enabled IS DISTINCT FROM TRUE;
    END IF;
END $$;
