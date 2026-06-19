<?php

const CHIM_CUSTOM_VERSION = '0.4.0';
const CHIM_CUSTOM_DEFAULT_STALE_SECONDS = 180;

function chimCustomDefaultIntegrations(): array
{
    return [
        'dirt_and_blood' => [
            'integration_id' => 'dirt_and_blood',
            'display_name' => 'Dirt and Blood - Dynamic Visual Effects',
            'description' => 'Adds visible dirt, blood, clean, and washing state from Dirt and Blood to CHIM prompts when the mod is installed.',
            'enabled' => true,
            'prompt_template' => '',
            'native_config' => [
                'required_plugins' => ['Dirt and Blood - Dynamic Visuals.esp'],
                'poll_seconds' => 8,
                'forms' => [
                    'dirt' => ['00000806', '00000807', '00000808', '00000838'],
                    'blood' => ['00000809', '0000080A', '0000080B', '00000839'],
                    'clean' => '0000080C',
                    'washing' => '0000081C',
                ],
            ],
        ],
        'bathing_in_skyrim' => [
            'integration_id' => 'bathing_in_skyrim',
            'display_name' => 'Bathing in Skyrim - Renewed',
            'description' => 'Adds player dirtiness, bathing, and soapy state from Bathing in Skyrim - Renewed to CHIM prompts when the mod is installed.',
            'enabled' => true,
            'prompt_template' => '',
            'native_config' => [
                'required_plugins' => ['Bathing in Skyrim.esp'],
                'poll_seconds' => 8,
                'forms' => [
                    'enabled' => '00000C',
                    'tier_clean' => '000043',
                    'tier_not_dirty' => '000044',
                    'tier_slightly_dirty' => '000045',
                    'tier_quite_dirty' => '000046',
                    'tier_filthy' => '00006D',
                    'bathing' => '00003D',
                    'soapy' => '000039',
                    'soapy_animated' => '00003B',
                ],
            ],
        ],
        'sunhelm_survival' => [
            'integration_id' => 'sunhelm_survival',
            'display_name' => 'SunHelm Survival',
            'description' => 'Adds player hunger, thirst, exhaustion, and cold state from SunHelm Survival to CHIM prompts when the mod is installed.',
            'enabled' => true,
            'prompt_template' => '',
            'native_config' => [
                'required_plugins' => ['SunHelmSurvival.esp'],
                'poll_seconds' => 8,
                'forms' => [
                    'survival_toggle' => '00A9AD94',
                    'hunger_level' => '0000EAAE',
                    'thirst_level' => '0005C472',
                    'exhaustion_level' => '00021E3F',
                    'cold_level' => '006A13C5',
                    'hunger_disabled' => '00752707',
                    'thirst_disabled' => '00752708',
                    'exhaustion_disabled' => '00752709',
                    'cold_disabled' => '0075270A',
                    'cold_active' => '0079441D',
                    'cold_force_disabled' => '0083132C',
                ],
            ],
        ],
        'starfrost_survival' => [
            'integration_id' => 'starfrost_survival',
            'display_name' => 'Starfrost - A Survival Overhaul',
            'description' => 'Adds player hunger, exhaustion, and cold state from Starfrost and Survival Mode Improved to CHIM prompts when those mods are installed.',
            'enabled' => true,
            'prompt_template' => '',
            'native_config' => [
                'required_plugins' => ['Starfrost.esp', 'SurvivalModeImproved.esp', 'ccQDRSSE001-SurvivalMode.esl'],
                'poll_seconds' => 8,
                'forms' => [
                    'survival_mode_enabled' => '00000826',
                    'hunger_started' => '00000860',
                    'hunger_spell_1' => '0000084E',
                    'hunger_spell_2' => '00000856',
                    'hunger_spell_3' => '00000857',
                    'exhaustion_stage' => '00000A1C',
                    'cold_stage' => '00000D1E',
                    'cold_enabled' => '00000F28',
                    'exhaustion_enabled' => '00000F29',
                ],
            ],
        ],
    ];
}

function chimCustomDbReady(): bool
{
    global $db;
    if (!isset($db)) {
        return false;
    }

    try {
        $row = $db->fetchOne("SELECT to_regclass('public.chim_custom_integrations') AS table_name");
        return is_array($row) && !empty($row['table_name']);
    } catch (Throwable $e) {
        return false;
    }
}

function chimCustomJsonEncode($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) && $json !== '' ? $json : '{}';
}

function chimCustomToBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $value = strtolower(trim((string) $value));
    return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
}

function chimCustomEnsureDefaults(): void
{
    global $db;
    if (!chimCustomDbReady()) {
        return;
    }

    foreach (chimCustomDefaultIntegrations() as $integration) {
        $id = $db->escape($integration['integration_id']);
        $name = $db->escape($integration['display_name']);
        $description = $db->escape($integration['description']);
        $config = $db->escapeLiteral(chimCustomJsonEncode($integration['native_config']));
        $prompt = $db->escape($integration['prompt_template']);
        $enabled = $integration['enabled'] ? 'TRUE' : 'FALSE';

        $db->execQuery("
            INSERT INTO public.chim_custom_integrations (
                integration_id,
                display_name,
                description,
                enabled,
                prompt_template,
                native_config
            ) VALUES (
                '{$id}',
                '{$name}',
                '{$description}',
                {$enabled},
                '{$prompt}',
                {$config}::jsonb
            ) ON CONFLICT (integration_id) DO NOTHING
        ");
    }
}

function chimCustomGetIntegrations(): array
{
    global $db;
    if (!chimCustomDbReady()) {
        return array_values(chimCustomDefaultIntegrations());
    }

    chimCustomEnsureDefaults();
    $rows = $db->fetchAll("
        SELECT integration_id, display_name, description, enabled, prompt_template, native_config, updated_at
        FROM public.chim_custom_integrations
        ORDER BY display_name ASC
    ");

    $integrations = [];
    foreach ($rows as $row) {
        $config = $row['native_config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }
        $row['native_config'] = is_array($config) ? $config : [];
        $row['enabled'] = chimCustomToBool($row['enabled'] ?? false);
        $integrations[] = $row;
    }

    return $integrations;
}

function chimCustomGetIntegration(string $integrationId): ?array
{
    foreach (chimCustomGetIntegrations() as $integration) {
        if (($integration['integration_id'] ?? '') === $integrationId) {
            return $integration;
        }
    }
    return null;
}

function chimCustomSetIntegrationSettings(string $integrationId, bool $enabled, string $promptTemplate): bool
{
    global $db;
    if (!chimCustomDbReady()) {
        return false;
    }

    $id = $db->escape($integrationId);
    $prompt = $db->escape($promptTemplate);
    $enabledSql = $enabled ? 'TRUE' : 'FALSE';

    $db->execQuery("
        UPDATE public.chim_custom_integrations
        SET enabled = {$enabledSql},
            prompt_template = '{$prompt}',
            updated_at = CURRENT_TIMESTAMP
        WHERE integration_id = '{$id}'
    ");

    return true;
}

function chimCustomActorKey(string $actorType, string $actorName, string $runtimeFormId = ''): string
{
    $actorType = strtolower(trim($actorType));
    $actorName = trim($actorName);
    $runtimeFormId = strtoupper(trim($runtimeFormId));

    if ($actorType === 'player') {
        return 'player';
    }
    if ($runtimeFormId !== '') {
        return 'form:' . $runtimeFormId;
    }
    return 'name:' . strtolower($actorName);
}

function chimCustomSanitizeDirtAndBloodState(array $state): array
{
    $dirtLevel = max(0, min(4, intval($state['dirt_level'] ?? 0)));
    $bloodLevel = max(0, min(4, intval($state['blood_level'] ?? 0)));
    $isWashing = !empty($state['is_washing']);
    $isClean = !empty($state['is_clean']);

    $status = 'normal';
    if ($isWashing) {
        $status = 'washing';
    } elseif ($dirtLevel > 0 && $bloodLevel > 0) {
        $status = 'dirty_bloodied';
    } elseif ($bloodLevel > 0) {
        $status = 'bloodied';
    } elseif ($dirtLevel > 0) {
        $status = 'dirty';
    } elseif ($isClean) {
        $status = 'clean';
    }

    return [
        'status' => $status,
        'dirt_level' => $dirtLevel,
        'blood_level' => $bloodLevel,
        'is_clean' => $isClean,
        'is_washing' => $isWashing,
        'source_mod' => trim((string) ($state['source_mod'] ?? 'Dirt and Blood - Dynamic Visuals.esp')),
    ];
}

function chimCustomClampNeedLevel($value): int
{
    return max(0, min(5, intval(round(floatval($value)))));
}

function chimCustomClampBathingTier($value): int
{
    return max(0, min(4, intval(round(floatval($value)))));
}

function chimCustomSanitizeBathingInSkyrimState(array $state): array
{
    $enabled = !empty($state['enabled']);
    $tier = chimCustomClampBathingTier($state['dirtiness_tier'] ?? 0);
    $isBathing = !empty($state['is_bathing']);
    $isSoapy = !empty($state['is_soapy']);

    $status = 'clean';
    if (!$enabled) {
        $status = 'disabled';
    } elseif ($isBathing && $isSoapy) {
        $status = 'soapy_bathing';
    } elseif ($isBathing) {
        $status = 'bathing';
    } elseif ($isSoapy) {
        $status = 'soapy';
    } elseif ($tier >= 4) {
        $status = 'filthy';
    } elseif ($tier === 3) {
        $status = 'quite_dirty';
    } elseif ($tier === 2) {
        $status = 'slightly_dirty';
    } elseif ($tier === 1) {
        $status = 'not_dirty';
    }

    return [
        'enabled' => $enabled,
        'status' => $status,
        'dirtiness_tier' => $tier,
        'dirtiness_description' => chimCustomBathingInSkyrimTierText($tier),
        'is_dirty' => $tier >= 2,
        'is_very_dirty' => $tier >= 3,
        'is_bathing' => $isBathing,
        'is_soapy' => $isSoapy,
        'source_mod' => trim((string) ($state['source_mod'] ?? 'Bathing in Skyrim.esp')),
    ];
}

function chimCustomSanitizeSunHelmState(array $state): array
{
    $enabled = !empty($state['enabled']);
    $hungerEnabled = $enabled && !empty($state['hunger_enabled']);
    $thirstEnabled = $enabled && !empty($state['thirst_enabled']);
    $exhaustionEnabled = $enabled && !empty($state['exhaustion_enabled']);
    $coldEnabled = $enabled && !empty($state['cold_enabled']);

    return [
        'enabled' => $enabled,
        'hunger_enabled' => $hungerEnabled,
        'thirst_enabled' => $thirstEnabled,
        'exhaustion_enabled' => $exhaustionEnabled,
        'cold_enabled' => $coldEnabled,
        'hunger_level' => chimCustomClampNeedLevel($state['hunger_level'] ?? 0),
        'thirst_level' => chimCustomClampNeedLevel($state['thirst_level'] ?? 0),
        'exhaustion_level' => chimCustomClampNeedLevel($state['exhaustion_level'] ?? 0),
        'cold_level' => chimCustomClampNeedLevel($state['cold_level'] ?? 0),
        'source_mod' => trim((string) ($state['source_mod'] ?? 'SunHelmSurvival.esp')),
    ];
}

function chimCustomSanitizeStarfrostState(array $state): array
{
    $enabled = !empty($state['enabled']);
    $hungerEnabled = $enabled && !empty($state['hunger_enabled']);
    $exhaustionEnabled = $enabled && !empty($state['exhaustion_enabled']);
    $coldEnabled = $enabled && !empty($state['cold_enabled']);

    return [
        'enabled' => $enabled,
        'hunger_enabled' => $hungerEnabled,
        'exhaustion_enabled' => $exhaustionEnabled,
        'cold_enabled' => $coldEnabled,
        'hunger_level' => max(0, min(3, intval(round(floatval($state['hunger_level'] ?? 0))))),
        'exhaustion_level' => chimCustomClampNeedLevel($state['exhaustion_level'] ?? 0),
        'cold_level' => chimCustomClampNeedLevel($state['cold_level'] ?? 0),
        'source_mod' => trim((string) ($state['source_mod'] ?? 'Starfrost.esp')),
    ];
}

function chimCustomUpsertActorState(array $payload): bool
{
    global $db;
    if (!chimCustomDbReady()) {
        return false;
    }

    $integrationId = trim((string) ($payload['integration_id'] ?? ''));
    $actorName = trim((string) ($payload['actor_name'] ?? ''));
    $actorType = strtolower(trim((string) ($payload['actor_type'] ?? '')));
    if ($integrationId === '' || $actorName === '' || $actorType === '') {
        return false;
    }

    $integration = chimCustomGetIntegration($integrationId);
    if (!$integration || empty($integration['enabled'])) {
        return false;
    }

    $state = is_array($payload['state'] ?? null) ? $payload['state'] : [];
    if ($integrationId === 'dirt_and_blood') {
        $state = chimCustomSanitizeDirtAndBloodState($state);
    } elseif ($integrationId === 'bathing_in_skyrim') {
        $state = chimCustomSanitizeBathingInSkyrimState($state);
    } elseif ($integrationId === 'sunhelm_survival') {
        $state = chimCustomSanitizeSunHelmState($state);
    } elseif ($integrationId === 'starfrost_survival') {
        $state = chimCustomSanitizeStarfrostState($state);
    }

    $actorKey = chimCustomActorKey($actorType, $actorName, (string) ($payload['runtime_formid'] ?? ''));
    $gamets = isset($payload['gamets']) && is_numeric($payload['gamets']) ? intval($payload['gamets']) : 0;

    $actorKeySql = $db->escape($actorKey);
    $actorNameSql = $db->escape($actorName);
    $actorTypeSql = $db->escape($actorType);
    $integrationSql = $db->escape($integrationId);
    $stateJson = $db->escapeLiteral(chimCustomJsonEncode($state));

    $db->execQuery("
        INSERT INTO public.chim_custom_actor_state (
            actor_key,
            actor_name,
            actor_type,
            integration_id,
            state,
            gamets,
            updated_at
        ) VALUES (
            '{$actorKeySql}',
            '{$actorNameSql}',
            '{$actorTypeSql}',
            '{$integrationSql}',
            {$stateJson}::jsonb,
            {$gamets},
            CURRENT_TIMESTAMP
        ) ON CONFLICT (actor_key, integration_id) DO UPDATE SET
            actor_name = EXCLUDED.actor_name,
            actor_type = EXCLUDED.actor_type,
            state = EXCLUDED.state,
            gamets = EXCLUDED.gamets,
            updated_at = CURRENT_TIMESTAMP
    ");

    return true;
}

function chimCustomRecordHeartbeat(array $payload): void
{
    global $db;
    if (!chimCustomDbReady()) {
        return;
    }

    $pluginId = $db->escape(trim((string) ($payload['plugin_id'] ?? 'CHIM-Custom')));
    $pluginVersion = $db->escape(trim((string) ($payload['plugin_version'] ?? '')));
    $gameVersion = $db->escape(trim((string) ($payload['game_version'] ?? '')));
    $payloadJson = $db->escapeLiteral(chimCustomJsonEncode($payload));

    $db->execQuery("
        INSERT INTO public.chim_custom_plugin_heartbeat (
            plugin_id,
            plugin_version,
            game_version,
            last_payload,
            updated_at
        ) VALUES (
            '{$pluginId}',
            '{$pluginVersion}',
            '{$gameVersion}',
            {$payloadJson}::jsonb,
            CURRENT_TIMESTAMP
        ) ON CONFLICT (plugin_id) DO UPDATE SET
            plugin_version = EXCLUDED.plugin_version,
            game_version = EXCLUDED.game_version,
            last_payload = EXCLUDED.last_payload,
            updated_at = CURRENT_TIMESTAMP
    ");
}

function chimCustomGetRecentStates(int $limit = 50): array
{
    global $db;
    if (!chimCustomDbReady()) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $rows = $db->fetchAll("
        SELECT actor_key, actor_name, actor_type, integration_id, state, gamets, updated_at
        FROM public.chim_custom_actor_state
        ORDER BY updated_at DESC
        LIMIT {$limit}
    ");

    foreach ($rows as &$row) {
        if (is_string($row['state'] ?? null)) {
            $decoded = json_decode($row['state'], true);
            $row['state'] = is_array($decoded) ? $decoded : [];
        }
    }

    return $rows;
}

function chimCustomGetHeartbeat(): ?array
{
    global $db;
    if (!chimCustomDbReady()) {
        return null;
    }

    $row = $db->fetchOne("
        SELECT plugin_id, plugin_version, game_version, last_payload, updated_at
        FROM public.chim_custom_plugin_heartbeat
        WHERE plugin_id = 'CHIM-Custom'
        LIMIT 1
    ");

    return is_array($row) ? $row : null;
}

function chimCustomIsStateFresh(array $row, int $staleSeconds = CHIM_CUSTOM_DEFAULT_STALE_SECONDS): bool
{
    $updatedAt = strtotime((string) ($row['updated_at'] ?? ''));
    if ($updatedAt <= 0) {
        return false;
    }
    return (time() - $updatedAt) <= $staleSeconds;
}

function chimCustomLevelText(string $kind, int $level): string
{
    if ($kind === 'dirt') {
        return [
            1 => 'slightly dirty',
            2 => 'dirty',
            3 => 'quite dirty',
            4 => 'covered in dirt',
        ][$level] ?? '';
    }

    return [
        1 => 'lightly bloodstained',
        2 => 'marked with large bloodstains',
        3 => 'quite bloody',
        4 => 'covered in blood',
    ][$level] ?? '';
}

function chimCustomSubjectBeVerb(string $subject): string
{
    $subject = strtolower(trim($subject));
    return in_array($subject, ['you', 'they', 'we'], true) ? 'are' : 'is';
}

function chimCustomSubjectLookVerb(string $subject): string
{
    $subject = strtolower(trim($subject));
    return in_array($subject, ['you', 'they', 'we'], true) ? 'look' : 'looks';
}

function chimCustomDescribeDirtAndBloodState(array $state): string
{
    $status = trim((string) ($state['status'] ?? 'normal'));
    if ($status === 'normal') {
        return '';
    }
    if ($status === 'washing') {
        return 'washing up';
    }
    if ($status === 'clean') {
        return 'freshly washed';
    }

    $parts = [];
    $dirt = chimCustomLevelText('dirt', intval($state['dirt_level'] ?? 0));
    $blood = chimCustomLevelText('blood', intval($state['blood_level'] ?? 0));
    if ($dirt !== '') {
        $parts[] = $dirt;
    }
    if ($blood !== '') {
        $parts[] = $blood;
    }

    return implode(' and ', $parts);
}

function chimCustomRenderDirtAndBloodState(array $state, string $subject, string $template = ''): string
{
    $subject = trim($subject) !== '' ? trim($subject) : 'They';
    $status = trim((string) ($state['status'] ?? 'normal'));
    if ($status === 'normal') {
        return '';
    }

    if ($status === 'washing') {
        $default = "{$subject} " . chimCustomSubjectBeVerb($subject) . " washing up.";
    } elseif ($status === 'clean') {
        $default = "{$subject} " . chimCustomSubjectLookVerb($subject) . " freshly washed.";
    } else {
        $description = chimCustomDescribeDirtAndBloodState($state);
        if ($description === '') {
            return '';
        }
        $default = "{$subject} " . chimCustomSubjectBeVerb($subject) . " {$description}.";
    }

    $template = trim($template);
    if ($template === '') {
        return $default;
    }

    return strtr($template, [
        '{subject}' => $subject,
        '{status}' => $status,
        '{dirt_level}' => (string) intval($state['dirt_level'] ?? 0),
        '{blood_level}' => (string) intval($state['blood_level'] ?? 0),
        '{dirt_description}' => chimCustomLevelText('dirt', intval($state['dirt_level'] ?? 0)),
        '{blood_description}' => chimCustomLevelText('blood', intval($state['blood_level'] ?? 0)),
    ]);
}

function chimCustomBathingInSkyrimTierText(int $tier): string
{
    return [
        0 => 'clean',
        1 => 'not dirty',
        2 => 'slightly dirty',
        3 => 'quite dirty',
        4 => 'filthy',
    ][$tier] ?? '';
}

function chimCustomDescribeBathingInSkyrimState(array $state): string
{
    if (empty($state['enabled'])) {
        return '';
    }

    $tier = chimCustomClampBathingTier($state['dirtiness_tier'] ?? 0);
    $tierDescription = chimCustomBathingInSkyrimTierText($tier);
    $isBathing = !empty($state['is_bathing']);
    $isSoapy = !empty($state['is_soapy']);

    if ($isBathing && $isSoapy) {
        return 'soapy and washing up';
    }
    if ($isBathing) {
        return 'washing up';
    }
    if ($isSoapy && $tier >= 2 && $tierDescription !== '') {
        return 'soapy and ' . $tierDescription;
    }
    if ($isSoapy) {
        return 'soapy';
    }
    if ($tier >= 2) {
        return $tierDescription;
    }

    return '';
}

function chimCustomRenderBathingInSkyrimState(array $state, string $subject, string $template = ''): string
{
    $subject = trim($subject) !== '' ? trim($subject) : 'They';
    $description = chimCustomDescribeBathingInSkyrimState($state);
    if ($description === '') {
        return '';
    }

    $default = "{$subject} " . chimCustomSubjectBeVerb($subject) . " {$description}.";
    $template = trim($template);
    if ($template === '') {
        return $default;
    }

    $tier = chimCustomClampBathingTier($state['dirtiness_tier'] ?? 0);
    return strtr($template, [
        '{subject}' => $subject,
        '{status}' => (string) ($state['status'] ?? ''),
        '{summary}' => $description,
        '{dirtiness_tier}' => (string) $tier,
        '{tier}' => (string) $tier,
        '{dirtiness_description}' => chimCustomBathingInSkyrimTierText($tier),
        '{is_dirty}' => !empty($state['is_dirty']) ? 'true' : 'false',
        '{is_very_dirty}' => !empty($state['is_very_dirty']) ? 'true' : 'false',
        '{is_bathing}' => !empty($state['is_bathing']) ? 'true' : 'false',
        '{is_soapy}' => !empty($state['is_soapy']) ? 'true' : 'false',
    ]);
}

function chimCustomSunHelmLevelText(string $need, int $level): string
{
    $labels = [
        'hunger' => [
            0 => 'well fed',
            1 => 'satisfied',
            2 => 'peckish',
            3 => 'hungry',
            4 => 'ravenous',
            5 => 'starving',
        ],
        'thirst' => [
            0 => 'quenched',
            1 => 'sated',
            2 => 'thirsty',
            3 => 'parched',
            4 => 'dehydrated',
            5 => 'severely dehydrated',
        ],
        'exhaustion' => [
            0 => 'well rested',
            1 => 'rested',
            2 => 'slightly tired',
            3 => 'tired',
            4 => 'weary',
            5 => 'exhausted',
        ],
        'cold' => [
            0 => 'warm',
            1 => 'comfortable',
            2 => 'chilly',
            3 => 'cold',
            4 => 'freezing',
            5 => 'frigid',
        ],
    ];

    return $labels[$need][$level] ?? '';
}

function chimCustomDescribeSunHelmState(array $state): string
{
    if (empty($state['enabled'])) {
        return '';
    }

    $parts = [];
    foreach (['hunger', 'thirst', 'exhaustion', 'cold'] as $need) {
        if (empty($state[$need . '_enabled'])) {
            continue;
        }
        $level = chimCustomClampNeedLevel($state[$need . '_level'] ?? 0);
        if ($level < 2) {
            continue;
        }
        $label = chimCustomSunHelmLevelText($need, $level);
        if ($label !== '') {
            $parts[] = $label;
        }
    }

    return implode(', ', $parts);
}

function chimCustomRenderSunHelmState(array $state, string $subject, string $template = ''): string
{
    $subject = trim($subject) !== '' ? trim($subject) : 'They';
    $summary = chimCustomDescribeSunHelmState($state);
    if ($summary === '') {
        return '';
    }

    $default = "{$subject} " . chimCustomSubjectBeVerb($subject) . " {$summary}.";
    $template = trim($template);
    if ($template === '') {
        return $default;
    }

    return strtr($template, [
        '{subject}' => $subject,
        '{summary}' => $summary,
        '{hunger_level}' => (string) chimCustomClampNeedLevel($state['hunger_level'] ?? 0),
        '{hunger_description}' => chimCustomSunHelmLevelText('hunger', chimCustomClampNeedLevel($state['hunger_level'] ?? 0)),
        '{thirst_level}' => (string) chimCustomClampNeedLevel($state['thirst_level'] ?? 0),
        '{thirst_description}' => chimCustomSunHelmLevelText('thirst', chimCustomClampNeedLevel($state['thirst_level'] ?? 0)),
        '{exhaustion_level}' => (string) chimCustomClampNeedLevel($state['exhaustion_level'] ?? 0),
        '{exhaustion_description}' => chimCustomSunHelmLevelText('exhaustion', chimCustomClampNeedLevel($state['exhaustion_level'] ?? 0)),
        '{cold_level}' => (string) chimCustomClampNeedLevel($state['cold_level'] ?? 0),
        '{cold_description}' => chimCustomSunHelmLevelText('cold', chimCustomClampNeedLevel($state['cold_level'] ?? 0)),
    ]);
}

function chimCustomStarfrostLevelText(string $need, int $level): string
{
    $labels = [
        'hunger' => [
            1 => 'hungry',
            2 => 'very hungry',
            3 => 'famished',
        ],
        'exhaustion' => [
            3 => 'tired',
            4 => 'very tired',
            5 => 'exhausted',
        ],
        'cold' => [
            3 => 'cold',
            4 => 'very cold',
            5 => 'freezing',
        ],
    ];

    return $labels[$need][$level] ?? '';
}

function chimCustomDescribeStarfrostState(array $state): string
{
    if (empty($state['enabled'])) {
        return '';
    }

    $parts = [];

    if (!empty($state['hunger_enabled'])) {
        $hunger = chimCustomStarfrostLevelText('hunger', max(0, min(3, intval($state['hunger_level'] ?? 0))));
        if ($hunger !== '') {
            $parts[] = $hunger;
        }
    }

    foreach (['exhaustion', 'cold'] as $need) {
        if (empty($state[$need . '_enabled'])) {
            continue;
        }
        $label = chimCustomStarfrostLevelText($need, chimCustomClampNeedLevel($state[$need . '_level'] ?? 0));
        if ($label !== '') {
            $parts[] = $label;
        }
    }

    return implode(', ', $parts);
}

function chimCustomRenderStarfrostState(array $state, string $subject, string $template = ''): string
{
    $subject = trim($subject) !== '' ? trim($subject) : 'They';
    $summary = chimCustomDescribeStarfrostState($state);
    if ($summary === '') {
        return '';
    }

    $default = "{$subject} " . chimCustomSubjectBeVerb($subject) . " {$summary}.";
    $template = trim($template);
    if ($template === '') {
        return $default;
    }

    return strtr($template, [
        '{subject}' => $subject,
        '{summary}' => $summary,
        '{hunger_level}' => (string) max(0, min(3, intval($state['hunger_level'] ?? 0))),
        '{hunger_description}' => chimCustomStarfrostLevelText('hunger', max(0, min(3, intval($state['hunger_level'] ?? 0)))),
        '{exhaustion_level}' => (string) chimCustomClampNeedLevel($state['exhaustion_level'] ?? 0),
        '{exhaustion_description}' => chimCustomStarfrostLevelText('exhaustion', chimCustomClampNeedLevel($state['exhaustion_level'] ?? 0)),
        '{cold_level}' => (string) chimCustomClampNeedLevel($state['cold_level'] ?? 0),
        '{cold_description}' => chimCustomStarfrostLevelText('cold', chimCustomClampNeedLevel($state['cold_level'] ?? 0)),
    ]);
}

function chimCustomFindFreshCleaninessStates(string $actorName, string $actorType = ''): array
{
    $rows = [];
    foreach (['dirt_and_blood', 'bathing_in_skyrim'] as $integrationId) {
        $row = chimCustomFindFreshActorState($actorName, $actorType, $integrationId);
        if ($row) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function chimCustomDescribeCleaninessRow(array $row): string
{
    $state = is_array($row['state'] ?? null) ? $row['state'] : [];
    $integrationId = (string) ($row['integration_id'] ?? '');

    if ($integrationId === 'bathing_in_skyrim') {
        return chimCustomDescribeBathingInSkyrimState($state);
    }

    return chimCustomDescribeDirtAndBloodState($state);
}

function chimCustomRenderCleaninessRow(array $row, string $subject): string
{
    $state = is_array($row['state'] ?? null) ? $row['state'] : [];
    $template = (string) (($row['integration']['prompt_template'] ?? '') ?: '');
    $integrationId = (string) ($row['integration_id'] ?? '');

    if ($integrationId === 'bathing_in_skyrim') {
        return chimCustomRenderBathingInSkyrimState($state, $subject, $template);
    }

    return chimCustomRenderDirtAndBloodState($state, $subject, $template);
}

function chimCustomFindFreshSurvivalState(string $actorName, string $actorType = 'player'): ?array
{
    foreach (['starfrost_survival', 'sunhelm_survival'] as $integrationId) {
        $row = chimCustomFindFreshActorState($actorName, $actorType, $integrationId);
        if ($row) {
            return $row;
        }
    }

    return null;
}

function chimCustomDescribeSurvivalState(array $row): string
{
    $state = is_array($row['state'] ?? null) ? $row['state'] : [];
    $integrationId = (string) ($row['integration_id'] ?? '');

    if ($integrationId === 'starfrost_survival') {
        return chimCustomDescribeStarfrostState($state);
    }

    return chimCustomDescribeSunHelmState($state);
}

function chimCustomRenderSurvivalState(array $row, string $subject): string
{
    $state = is_array($row['state'] ?? null) ? $row['state'] : [];
    $template = (string) (($row['integration']['prompt_template'] ?? '') ?: '');
    $integrationId = (string) ($row['integration_id'] ?? '');

    if ($integrationId === 'starfrost_survival') {
        return chimCustomRenderStarfrostState($state, $subject, $template);
    }

    return chimCustomRenderSunHelmState($state, $subject, $template);
}

function chimCustomFindFreshActorState(string $actorName, string $actorType = '', string $integrationId = 'dirt_and_blood'): ?array
{
    static $recentRows = null;
    static $integrationsById = null;

    $actorName = trim($actorName);
    $actorType = strtolower(trim($actorType));
    if ($actorName === '' && $actorType !== 'player') {
        return null;
    }

    if ($integrationsById === null) {
        $integrationsById = [];
        foreach (chimCustomGetIntegrations() as $integration) {
            $integrationsById[$integration['integration_id']] = $integration;
        }
    }
    if ($recentRows === null) {
        $recentRows = chimCustomGetRecentStates(100);
    }

    foreach ($recentRows as $row) {
        if (($row['integration_id'] ?? '') !== $integrationId || !chimCustomIsStateFresh($row)) {
            continue;
        }

        $integration = $integrationsById[$integrationId] ?? null;
        if (!$integration || empty($integration['enabled'])) {
            continue;
        }

        $rowActorType = strtolower((string) ($row['actor_type'] ?? ''));
        $rowActorName = trim((string) ($row['actor_name'] ?? ''));
        $rowActorKey = strtolower((string) ($row['actor_key'] ?? ''));

        if ($actorType === 'player') {
            if ($rowActorType === 'player' || $rowActorKey === 'player' || strcasecmp($rowActorName, $actorName) === 0) {
                $row['integration'] = $integration;
                return $row;
            }
            continue;
        }

        if ($actorType !== '' && $rowActorType !== '' && $rowActorType !== $actorType) {
            continue;
        }
        if ($actorName !== '' && strcasecmp($rowActorName, $actorName) === 0) {
            $row['integration'] = $integration;
            return $row;
        }
    }

    return null;
}

function chimCustomBuildCurrentCharacterCleaninessBlock(string $npcName, string $playerName): string
{
    $npcName = trim($npcName);
    if ($npcName === '') {
        return '';
    }

    $actorType = strcasecmp($npcName, $playerName) === 0 ? 'player' : 'npc';
    foreach (chimCustomFindFreshCleaninessStates($npcName, $actorType) as $row) {
        $line = chimCustomRenderCleaninessRow($row, 'You');
        if ($line !== '') {
            return "<cleaniness>\n{$line}\n</cleaniness>";
        }
    }

    return '';
}

function chimCustomBuildCurrentCharacterSurvivalBlock(string $npcName, string $playerName): string
{
    $npcName = trim($npcName);
    if ($npcName === '' || strcasecmp($npcName, $playerName) !== 0) {
        return '';
    }

    $row = chimCustomFindFreshSurvivalState($playerName, 'player');
    if (!$row) {
        return '';
    }

    $line = chimCustomRenderSurvivalState($row, 'You');
    if ($line === '') {
        return '';
    }

    return "<survival>\n{$line}\n</survival>";
}

function chimCustomActorProfileCleaniness(string $actorName, string $actorType, array $context = []): string
{
    foreach (chimCustomFindFreshCleaninessStates($actorName, $actorType) as $row) {
        $description = chimCustomDescribeCleaninessRow($row);
        if ($description !== '') {
            return 'Cleaniness: ' . $description;
        }
    }

    return '';
}

function chimCustomActorProfileSurvival(string $actorName, string $actorType, array $context = []): string
{
    $row = chimCustomFindFreshSurvivalState($actorName, $actorType);
    if (!$row) {
        return '';
    }

    $description = chimCustomDescribeSurvivalState($row);
    if ($description === '') {
        return '';
    }

    return 'Survival: ' . $description;
}

function chimCustomRegisterPromptHooks(): void
{
    if (function_exists('chimRegisterActorProfileEnricher')) {
        chimRegisterActorProfileEnricher('chim_custom.cleaniness', 'chimCustomActorProfileCleaniness', 50);
        chimRegisterActorProfileEnricher('chim_custom.survival', 'chimCustomActorProfileSurvival', 55);
        return;
    }

    if (!isset($GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS']) || !is_array($GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'])) {
        $GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'] = [];
    }

    $hasCleaniness = false;
    $hasSurvival = false;
    foreach ($GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'] as $enricher) {
        if ($enricher === 'chimCustomActorProfileCleaniness') {
            $hasCleaniness = true;
        }
        if ($enricher === 'chimCustomActorProfileSurvival') {
            $hasSurvival = true;
        }
    }

    if (!$hasCleaniness) {
        $GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'][] = 'chimCustomActorProfileCleaniness';
    }
    if (!$hasSurvival) {
        $GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'][] = 'chimCustomActorProfileSurvival';
    }
}
