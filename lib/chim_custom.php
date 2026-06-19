<?php

const CHIM_CUSTOM_VERSION = '0.1.1';
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
    $row = chimCustomFindFreshActorState($npcName, $actorType);
    if (!$row) {
        return '';
    }

    $state = is_array($row['state'] ?? null) ? $row['state'] : [];
    $line = chimCustomRenderDirtAndBloodState($state, 'You', (string) (($row['integration']['prompt_template'] ?? '') ?: ''));
    if ($line === '') {
        return '';
    }

    return "<cleaniness>\n{$line}\n</cleaniness>";
}

function chimCustomActorProfileCleaniness(string $actorName, string $actorType, array $context = []): string
{
    $row = chimCustomFindFreshActorState($actorName, $actorType);
    if (!$row) {
        return '';
    }

    $state = is_array($row['state'] ?? null) ? $row['state'] : [];
    $description = chimCustomDescribeDirtAndBloodState($state);
    if ($description === '') {
        return '';
    }

    return 'Cleaniness: ' . $description;
}

function chimCustomRegisterPromptHooks(): void
{
    if (function_exists('chimRegisterActorProfileEnricher')) {
        chimRegisterActorProfileEnricher('chim_custom.cleaniness', 'chimCustomActorProfileCleaniness', 50);
        return;
    }

    if (!isset($GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS']) || !is_array($GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'])) {
        $GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'] = [];
    }

    foreach ($GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'] as $enricher) {
        if ($enricher === 'chimCustomActorProfileCleaniness') {
            return;
        }
    }

    $GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS'][] = 'chimCustomActorProfileCleaniness';
}
