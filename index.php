<?php

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
chimRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_player_name' => true,
    'load_narrator' => true,
]);
$GLOBALS['db'] = $GLOBALS['db'] ?? new sql();

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_custom.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['integration_id'])) {
    $integrationId = trim((string) $_POST['integration_id']);
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
    $promptTemplate = trim((string) ($_POST['prompt_template'] ?? ''));
    $ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';
    $eventEnabledByKey = [];
    $knownEventKeys = $_POST['event_known'] ?? [];
    $enabledEventKeys = $_POST['event_enabled'] ?? [];
    if (!is_array($knownEventKeys)) {
        $knownEventKeys = [];
    }
    if (!is_array($enabledEventKeys)) {
        $enabledEventKeys = [];
    }
    foreach ($knownEventKeys as $eventKey) {
        $eventKey = trim((string) $eventKey);
        if ($eventKey !== '') {
            $eventEnabledByKey[$eventKey] = isset($enabledEventKeys[$eventKey]) && (string) $enabledEventKeys[$eventKey] === '1';
        }
    }
    $defaultPromptTemplate = trim(ccIntegrationDefaultPrompt($integrationId));
    if ($defaultPromptTemplate !== '' && $promptTemplate === $defaultPromptTemplate) {
        $promptTemplate = '';
    }

    if (chimCustomSetIntegrationSettings($integrationId, $enabled, $promptTemplate, $eventEnabledByKey)) {
        if ($ajax) {
            header('Content-Type: application/json');
            echo chimCustomJsonEncode([
                'ok' => true,
                'integration_id' => $integrationId,
                'enabled' => $enabled,
                'events' => $eventEnabledByKey,
            ]);
            exit;
        }
        $message = 'Saved integration settings.';
    } else {
        if ($ajax) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo chimCustomJsonEncode([
                'ok' => false,
                'error' => 'database_not_ready',
            ]);
            exit;
        }
        $error = 'Could not save settings. Run the CHIM-Custom plugin installer migrations first.';
    }
}

function ccH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ccIntegrationStatus(array $integration): string
{
    return !empty($integration['enabled']) ? 'Enabled' : 'Disabled';
}

function ccIntegrationEventRules(array $integration): array
{
    $nativeConfig = is_array($integration['native_config'] ?? null) ? $integration['native_config'] : [];
    $events = is_array($nativeConfig['events'] ?? null) ? $nativeConfig['events'] : [];
    $rules = [];
    foreach ($events as $eventKey => $eventConfig) {
        if (!is_array($eventConfig)) {
            $eventConfig = [];
        }
        $rules[(string) $eventKey] = [
            'enabled' => !array_key_exists('enabled', $eventConfig) || chimCustomToBool($eventConfig['enabled']),
            'cooldown_seconds' => max(0, intval($eventConfig['cooldown_seconds'] ?? 300)),
        ];
    }
    return $rules;
}

function ccIntegrationHasEnabledEvents(array $integration): bool
{
    foreach (ccIntegrationEventRules($integration) as $eventRule) {
        if (!empty($eventRule['enabled'])) {
            return true;
        }
    }
    return false;
}

function ccIntegrationEventLabel(string $integrationId, string $eventKey): string
{
    if ($integrationId === 'bathing_in_skyrim' && $eventKey === 'cleaned_up') {
        return 'Action Events';
    }
    return ucwords(str_replace('_', ' ', $eventKey));
}

function ccIntegrationEventDescription(string $integrationId, string $eventKey, array $eventRule): string
{
    $cooldown = intval($eventRule['cooldown_seconds'] ?? 0);
    if ($integrationId === 'bathing_in_skyrim' && $eventKey === 'cleaned_up') {
        return 'Track bathing actions within the eventlog.';
    }
    return $cooldown > 0 ? "Cooldown {$cooldown}s" : 'No cooldown';
}

function ccIntegrationPromptTokens(string $integrationId): array
{
    if ($integrationId === 'bathing_in_skyrim') {
        return [
            '{subject}',
            '{status}',
            '{summary}',
            '{dirtiness_tier}',
            '{tier}',
            '{dirtiness_description}',
            '{is_dirty}',
            '{is_very_dirty}',
            '{is_bathing}',
            '{is_soapy}',
        ];
    }

    if ($integrationId === 'sunhelm_survival' || $integrationId === 'starfrost_survival') {
        $tokens = [
            '{subject}',
            '{summary}',
            '{hunger_level}',
            '{hunger_description}',
        ];
        if ($integrationId === 'sunhelm_survival') {
            $tokens[] = '{thirst_level}';
            $tokens[] = '{thirst_description}';
        }
        $tokens[] = '{exhaustion_level}';
        $tokens[] = '{exhaustion_description}';
        $tokens[] = '{cold_level}';
        $tokens[] = '{cold_description}';
        return $tokens;
    }

    return [
        '{subject}',
        '{status}',
        '{dirt_level}',
        '{blood_level}',
        '{dirt_description}',
        '{blood_description}',
    ];
}

function ccIntegrationSampleValues(string $integrationId): array
{
    if ($integrationId === 'bathing_in_skyrim') {
        return [
            'subject' => 'Rangroo',
            'status' => 'quite_dirty',
            'summary' => 'quite dirty',
            'dirtiness_tier' => '3',
            'tier' => '3',
            'dirtiness_description' => 'quite dirty',
            'is_dirty' => 'true',
            'is_very_dirty' => 'true',
            'is_bathing' => 'false',
            'is_soapy' => 'false',
        ];
    }

    if ($integrationId === 'starfrost_survival') {
        return [
            'subject' => 'Rangroo',
            'summary' => 'hungry, very tired, and very cold',
            'hunger_level' => '1',
            'hunger_description' => 'hungry',
            'exhaustion_level' => '4',
            'exhaustion_description' => 'very tired',
            'cold_level' => '4',
            'cold_description' => 'very cold',
        ];
    }

    if ($integrationId === 'sunhelm_survival') {
        return [
            'subject' => 'Rangroo',
            'summary' => 'hungry, parched, weary, and cold',
            'hunger_level' => '3',
            'hunger_description' => 'hungry',
            'thirst_level' => '3',
            'thirst_description' => 'parched',
            'exhaustion_level' => '4',
            'exhaustion_description' => 'weary',
            'cold_level' => '3',
            'cold_description' => 'cold',
        ];
    }

    return [
        'subject' => 'Rangroo',
        'status' => 'dirty_bloodied',
        'dirt_level' => '2',
        'blood_level' => '1',
        'dirt_description' => 'dirty',
        'blood_description' => 'lightly bloodstained',
    ];
}

function ccIntegrationDefaultPrompt(string $integrationId): string
{
    if ($integrationId === 'bathing_in_skyrim') {
        return '{subject} is {summary}.';
    }

    if ($integrationId === 'sunhelm_survival' || $integrationId === 'starfrost_survival') {
        return '{subject} is {summary}.';
    }

    return '{subject} is {dirt_description} and {blood_description}.';
}

function ccIntegrationDefaultOutput(string $integrationId): string
{
    if ($integrationId === 'bathing_in_skyrim') {
        return 'Rangroo is quite dirty.';
    }

    if ($integrationId === 'starfrost_survival') {
        return 'Rangroo is hungry, very tired, and very cold.';
    }

    if ($integrationId === 'sunhelm_survival') {
        return 'Rangroo is hungry, parched, weary, and cold.';
    }

    return 'Rangroo is dirty and lightly bloodstained.';
}

$integrations = chimCustomGetIntegrations();
$states = chimCustomGetRecentStates(100);
$heartbeat = chimCustomGetHeartbeat();
$firstIntegrationId = (string) ($integrations[0]['integration_id'] ?? '');
$sampleValuesByIntegration = [];
$defaultPromptByIntegration = [];
$defaultOutputByIntegration = [];
foreach ($integrations as $integration) {
    $integrationId = (string) ($integration['integration_id'] ?? '');
    $sampleValuesByIntegration[$integrationId] = ccIntegrationSampleValues($integrationId);
    $defaultPromptByIntegration[$integrationId] = ccIntegrationDefaultPrompt($integrationId);
    $defaultOutputByIntegration[$integrationId] = ccIntegrationDefaultOutput($integrationId);
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CHIM-Custom</title>
    <link rel="stylesheet" href="../../ui/css/main.css">
    <style>
        :root {
            --cc-bg: #202020;
            --cc-panel: #2a2a2a;
            --cc-panel-soft: #252525;
            --cc-border: #3a3a3a;
            --cc-border-strong: rgba(242, 124, 17, 0.45);
            --cc-text: #f8f9fa;
            --cc-muted: #b8b8b8;
            --cc-accent: rgb(242, 124, 17);
            --cc-ok: #88d18a;
            --cc-warn: #ffbf66;
            --cc-danger: #ff6b6b;
        }

        body {
            padding: 24px;
            background: var(--cc-bg);
            color: var(--cc-text);
        }

        .cc-wrap {
            max-width: 1488px;
            margin: 0 auto;
        }

        .cc-panel {
            border: 1px solid var(--cc-border);
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.98), rgba(35, 35, 35, 0.98));
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .cc-page-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: start;
        }

        .cc-page-head h1,
        .cc-panel h2,
        .cc-editor-title h3 {
            margin-top: 0;
        }

        .cc-page-head h1 {
            margin-bottom: 6px;
        }

        .cc-muted {
            color: var(--cc-muted);
        }

        .cc-ok {
            color: var(--cc-ok);
        }

        .cc-warn {
            color: var(--cc-warn);
        }

        .cc-badge-row,
        .cc-actions,
        .cc-variable-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .cc-badge {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 4px 10px;
            border: 1px solid var(--cc-border);
            border-radius: 6px;
            background: #1b1b1b;
            color: var(--cc-text);
            font-size: 0.92rem;
        }

        .cc-badge.ok {
            border-color: rgba(136, 209, 138, 0.55);
            color: var(--cc-ok);
        }

        .cc-badge.warn {
            border-color: rgba(255, 191, 102, 0.55);
            color: var(--cc-warn);
        }

        .cc-badge.accent {
            border-color: var(--cc-border-strong);
            color: var(--cc-accent);
        }

        .cc-message {
            border: 1px solid rgba(136, 209, 138, 0.45);
            background: rgba(136, 209, 138, 0.08);
            color: var(--cc-ok);
            padding: 10px 12px;
            border-radius: 6px;
            margin: 12px 0 0;
        }

        .cc-message.warn {
            border-color: rgba(255, 191, 102, 0.5);
            background: rgba(255, 191, 102, 0.08);
            color: var(--cc-warn);
        }

        .cc-manager {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr);
            gap: 16px;
        }

        .cc-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .cc-list-item {
            display: grid;
            grid-template-columns: 52px minmax(0, 1fr);
            align-items: stretch;
            border: 1px solid var(--cc-border);
            border-radius: 8px;
            background: #222;
            transition: border-color 0.16s ease, background 0.16s ease;
        }

        .cc-list-item:hover,
        .cc-list-item.active {
            border-color: var(--cc-border-strong);
            background: #272727;
        }

        .cc-list-item.saving {
            border-color: rgba(255, 191, 102, 0.55);
        }

        .cc-list-item.error {
            border-color: rgba(255, 107, 107, 0.65);
        }

        .cc-list-button {
            appearance: none;
            border: 0;
            border-radius: 0 8px 8px 0;
            background: transparent;
            color: var(--cc-text);
            padding: 12px;
            text-align: left;
            cursor: pointer;
        }

        .cc-list-button:focus-visible,
        .cc-selector-toggle:focus-within {
            outline: 2px solid rgba(242, 124, 17, 0.55);
            outline-offset: 2px;
        }

        .cc-list-title {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .cc-list-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            color: var(--cc-muted);
            font-size: 0.86rem;
        }

        .cc-selector-toggle {
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            gap: 4px;
            border-right: 1px solid var(--cc-border);
            padding: 0;
            color: var(--cc-text);
            cursor: pointer;
            user-select: none;
            align-items: center;
            border-radius: 8px 0 0 8px;
        }

        .cc-selector-toggle input {
            margin: 0;
            width: 24px;
            height: 24px;
            accent-color: var(--cc-accent);
        }

        .cc-selector-toggle-main {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            font-weight: bold;
            width: 100%;
            height: 100%;
        }

        .cc-selector-toggle-status {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
        }

        .cc-selector-toggle-text {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
        }

        .cc-selector-toggle-status-visible {
            color: var(--cc-muted);
            font-size: 0.78rem;
            line-height: 1.2;
        }

        .cc-list-item.saving .cc-selector-toggle-status {
            color: var(--cc-warn);
        }

        .cc-list-item.error .cc-selector-toggle-status {
            color: var(--cc-danger);
        }

        .cc-editor {
            display: none;
        }

        .cc-editor.active {
            display: block;
        }

        .cc-editor-head {
            margin-bottom: 16px;
        }

        .cc-form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 24px;
            align-items: start;
        }

        .cc-field-label {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 8px;
            color: var(--cc-accent);
            font-weight: bold;
        }

        textarea.cc-prompt {
            box-sizing: border-box;
            width: 100%;
            min-height: 220px;
            resize: vertical;
            background: #151515;
            color: var(--cc-text);
            border: 1px solid #444;
            border-radius: 8px;
            padding: 12px;
            line-height: 1.45;
            font-family: "Spline Sans Mono", Consolas, monospace;
        }

        textarea.cc-prompt:focus {
            outline: none;
            border-color: var(--cc-border-strong);
            box-shadow: 0 0 0 2px rgba(242, 124, 17, 0.12);
        }

        .cc-side-box {
            box-sizing: border-box;
            border: 1px solid var(--cc-border);
            background: var(--cc-panel-soft);
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 12px;
            overflow-wrap: anywhere;
        }

        .cc-side-box h4 {
            margin: 0 0 10px;
            color: var(--cc-accent);
        }

        .cc-token {
            border: 1px solid var(--cc-border);
            background: #171717;
            color: #ddd;
            border-radius: 6px;
            padding: 5px 8px;
            font-family: "Spline Sans Mono", Consolas, monospace;
            cursor: pointer;
        }

        .cc-token:hover {
            border-color: var(--cc-border-strong);
            color: var(--cc-text);
        }

        .cc-preview {
            border-left: 3px solid var(--cc-accent);
            padding-left: 12px;
            color: #e8e8e8;
            min-height: 44px;
        }

        .cc-option-list {
            display: grid;
            gap: 10px;
        }

        .cc-option-row {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            padding: 10px;
            border: 1px solid var(--cc-border);
            border-radius: 8px;
            background: #1b1b1b;
        }

        .cc-option-row input {
            margin-top: 3px;
        }

        .cc-option-title {
            display: block;
            color: var(--cc-text);
            font-weight: bold;
        }

        .cc-option-help {
            display: block;
            margin-top: 3px;
            color: var(--cc-muted);
            font-size: 0.86rem;
            line-height: 1.35;
        }

        .cc-event-section {
            margin-top: 16px;
        }

        .cc-default-line {
            color: #e8e8e8;
            font-family: "Spline Sans Mono", Consolas, monospace;
            font-size: 0.9rem;
            line-height: 1.45;
        }

        .cc-table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border-bottom: 1px solid var(--cc-border);
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }

        th {
            color: var(--cc-accent);
        }

        .cc-json {
            display: block;
            max-width: 560px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            color: #ddd;
            font-size: 0.86rem;
        }

        @media (max-width: 980px) {
            .cc-manager,
            .cc-form-grid,
            .cc-page-head {
                grid-template-columns: 1fr;
            }

            .cc-list-item {
                grid-template-columns: 52px minmax(0, 1fr);
            }

            .cc-list-button {
                border-radius: 0 8px 8px 0;
            }

            .cc-selector-toggle {
                border-right: 1px solid var(--cc-border);
                border-top: 0;
            }
        }
    </style>
</head>
<body>
<main class="cc-wrap">
    <section class="cc-panel cc-page-head">
        <div>
            <h1>CHIM-Custom</h1>
            <?php if (!chimCustomDbReady()): ?>
                <p class="cc-message warn">Database tables are not installed yet. Install or update CHIM-Custom through Server Plugins so migrations run.</p>
            <?php endif; ?>
            <?php if ($message !== ''): ?><p class="cc-message"><?php echo ccH($message); ?></p><?php endif; ?>
            <?php if ($error !== ''): ?><p class="cc-message warn"><?php echo ccH($error); ?></p><?php endif; ?>
        </div>
    </section>

    <section class="cc-panel">
        <h2>Mod Customization</h2>
        <div class="cc-manager">
            <aside>
                <div class="cc-list" role="tablist" aria-label="CHIM-Custom integrations">
                    <?php foreach ($integrations as $index => $integration): ?>
                        <?php
                        $integrationId = (string) ($integration['integration_id'] ?? '');
                        $isActive = $integrationId === $firstIntegrationId;
                        $hasCustomPrompt = trim((string) ($integration['prompt_template'] ?? '')) !== '';
                        $hasEventRules = !empty(ccIntegrationEventRules($integration));
                        ?>
                        <div
                            class="cc-list-item <?php echo $isActive ? 'active' : ''; ?>"
                            data-list-item="<?php echo ccH($integrationId); ?>"
                        >
                            <label class="cc-selector-toggle" title="<?php echo ccH(ccIntegrationStatus($integration)); ?>">
                                <span class="cc-selector-toggle-main">
                                    <input
                                        type="checkbox"
                                        data-enable-toggle="<?php echo ccH($integrationId); ?>"
                                        <?php echo !empty($integration['enabled']) ? 'checked' : ''; ?>
                                    >
                                    <span class="cc-selector-toggle-text" data-enable-label="<?php echo ccH($integrationId); ?>"><?php echo ccH(ccIntegrationStatus($integration)); ?></span>
                                </span>
                                <span class="cc-selector-toggle-status" data-enable-status="<?php echo ccH($integrationId); ?>">Saved</span>
                            </label>
                            <button
                                type="button"
                                class="cc-list-button"
                                data-target="<?php echo ccH($integrationId); ?>"
                                role="tab"
                                aria-selected="<?php echo $isActive ? 'true' : 'false'; ?>"
                            >
                                <span class="cc-list-title">
                                    <span><?php echo ccH($integration['display_name'] ?? $integrationId); ?></span>
                                </span>
                                <span class="cc-list-meta">
                                    <span class="cc-badge"><?php echo $hasCustomPrompt ? 'Custom prompt' : 'Default prompt'; ?></span>
                                    <?php if ($hasEventRules): ?>
                                        <span class="cc-badge <?php echo ccIntegrationHasEnabledEvents($integration) ? 'ok' : 'warn'; ?>"><?php echo ccIntegrationHasEnabledEvents($integration) ? 'Events on' : 'Events off'; ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>

            <div>
                <?php foreach ($integrations as $integration): ?>
                    <?php
                    $integrationId = (string) ($integration['integration_id'] ?? '');
                    $isActive = $integrationId === $firstIntegrationId;
                    $savedPromptTemplate = (string) ($integration['prompt_template'] ?? '');
                    $defaultPromptTemplate = ccIntegrationDefaultPrompt($integrationId);
                    $promptTemplate = trim($savedPromptTemplate) !== '' ? $savedPromptTemplate : $defaultPromptTemplate;
                    $eventRules = ccIntegrationEventRules($integration);
                    ?>
                    <form method="post" class="cc-editor <?php echo $isActive ? 'active' : ''; ?>" data-editor="<?php echo ccH($integrationId); ?>">
                        <input type="hidden" name="integration_id" value="<?php echo ccH($integrationId); ?>">
                        <input type="hidden" name="enabled" value="<?php echo !empty($integration['enabled']) ? '1' : '0'; ?>" data-enabled-input="<?php echo ccH($integrationId); ?>">
                        <div class="cc-editor-head">
                            <div class="cc-editor-title">
                                <h3><?php echo ccH($integration['display_name'] ?? $integrationId); ?></h3>
                                <p class="cc-muted"><?php echo ccH($integration['description'] ?? ''); ?></p>
                            </div>
                        </div>

                        <div class="cc-form-grid">
                            <div>
                                <div class="cc-field-label">
                                    <label for="prompt-template-<?php echo ccH($integrationId); ?>">Prompt Template</label>
                                    <span class="cc-muted">Default shown when no custom prompt is saved</span>
                                </div>
                                <textarea
                                    id="prompt-template-<?php echo ccH($integrationId); ?>"
                                    class="cc-prompt"
                                    name="prompt_template"
                                    placeholder="<?php echo ccH($defaultPromptTemplate); ?>"
                                    data-preview="<?php echo ccH($integrationId); ?>"
                                ><?php echo ccH($promptTemplate); ?></textarea>
                                <div class="cc-actions" style="margin-top: 12px;">
                                    <button type="submit" class="btn-base btn-save">Save Changes</button>
                                    <button type="button" class="btn-base btn-primary" data-reset="<?php echo ccH($integrationId); ?>">Reset to Default</button>
                                </div>

                                <?php if (!empty($eventRules)): ?>
                                    <div class="cc-side-box cc-event-section">
                                        <h4>Event Log</h4>
                                        <div class="cc-option-list">
                                            <?php foreach ($eventRules as $eventKey => $eventRule): ?>
                                                <label class="cc-option-row">
                                                    <input type="hidden" name="event_known[]" value="<?php echo ccH($eventKey); ?>">
                                                    <input
                                                        type="checkbox"
                                                        name="event_enabled[<?php echo ccH($eventKey); ?>]"
                                                        value="1"
                                                        <?php echo !empty($eventRule['enabled']) ? 'checked' : ''; ?>
                                                    >
                                                    <span>
                                                        <span class="cc-option-title"><?php echo ccH(ccIntegrationEventLabel($integrationId, $eventKey)); ?></span>
                                                        <span class="cc-option-help"><?php echo ccH(ccIntegrationEventDescription($integrationId, $eventKey, $eventRule)); ?></span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <aside>
                                <div class="cc-side-box">
                                    <h4>Variables</h4>
                                    <div class="cc-variable-row">
                                        <?php foreach (ccIntegrationPromptTokens($integrationId) as $token): ?>
                                            <button type="button" class="cc-token" data-insert="<?php echo ccH($token); ?>" data-for="<?php echo ccH($integrationId); ?>"><?php echo ccH($token); ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="cc-side-box">
                                    <h4>Default Output</h4>
                                    <div class="cc-default-line"><?php echo ccH(ccIntegrationDefaultOutput($integrationId)); ?></div>
                                </div>

                                <div class="cc-side-box">
                                    <h4>Preview</h4>
                                    <div class="cc-preview" data-preview-output="<?php echo ccH($integrationId); ?>"></div>
                                </div>
                            </aside>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="cc-panel">
        <h2>Recent Events</h2>
        <div class="cc-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Actor</th>
                        <th>Integration</th>
                        <th>State</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($states)): ?>
                    <tr><td colspan="4" class="cc-muted">No state has been received yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($states as $row): ?>
                    <tr>
                        <td><?php echo ccH(($row['actor_name'] ?? '') . ' (' . ($row['actor_type'] ?? '') . ')'); ?></td>
                        <td><?php echo ccH($row['integration_id'] ?? ''); ?></td>
                        <td><code class="cc-json"><?php echo ccH(chimCustomJsonEncode($row['state'] ?? [])); ?></code></td>
                        <td><?php echo ccH($row['updated_at'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
(function () {
    const samples = <?php echo json_encode($sampleValuesByIntegration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const defaultPrompts = <?php echo json_encode($defaultPromptByIntegration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const defaultOutputs = <?php echo json_encode($defaultOutputByIntegration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    function renderTemplate(id, value) {
        const template = (value || '').trim();
        if (template === '') {
            return defaultOutputs[id] || '';
        }
        const sample = samples[id] || {};
        return template.replace(/\{[a-zA-Z0-9_]+\}/g, function (token) {
            const key = token.slice(1, -1);
            return Object.prototype.hasOwnProperty.call(sample, key) ? sample[key] : token;
        });
    }

    function findByData(attribute, value) {
        return Array.prototype.find.call(document.querySelectorAll('[' + attribute + ']'), function (element) {
            return element.getAttribute(attribute) === value;
        }) || null;
    }

    function updatePreview(id) {
        const textarea = findByData('data-preview', id);
        const output = findByData('data-preview-output', id);
        if (!textarea || !output) {
            return;
        }
        output.textContent = renderTemplate(id, textarea.value);
    }

    function activateEditor(id) {
        document.querySelectorAll('.cc-list-item').forEach(function (item) {
            item.classList.toggle('active', item.getAttribute('data-list-item') === id);
        });
        document.querySelectorAll('.cc-list-button').forEach(function (button) {
            const active = button.getAttribute('data-target') === id;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        document.querySelectorAll('.cc-editor').forEach(function (editor) {
            editor.classList.toggle('active', editor.getAttribute('data-editor') === id);
        });
        updatePreview(id);
    }

    function insertAtCursor(textarea, token) {
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const value = textarea.value || '';
        textarea.value = value.slice(0, start) + token + value.slice(end);
        const nextPosition = start + token.length;
        textarea.focus();
        textarea.setSelectionRange(nextPosition, nextPosition);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function setEnabledUi(id, enabled, status, error) {
        const item = findByData('data-list-item', id);
        const toggle = findByData('data-enable-toggle', id);
        const label = findByData('data-enable-label', id);
        const statusElement = findByData('data-enable-status', id);
        const hiddenInput = findByData('data-enabled-input', id);

        if (item) {
            item.classList.toggle('saving', status === 'Saving...');
            item.classList.toggle('error', !!error);
        }
        if (toggle) {
            toggle.checked = enabled;
            const wrapper = toggle.closest('.cc-selector-toggle');
            if (wrapper) {
                wrapper.setAttribute('title', enabled ? 'Enabled' : 'Disabled');
            }
        }
        if (label) {
            label.textContent = enabled ? 'Enabled' : 'Disabled';
        }
        if (statusElement) {
            statusElement.textContent = status || 'Saved';
        }
        if (hiddenInput) {
            hiddenInput.value = enabled ? '1' : '0';
        }
    }

    function saveEnabled(id, enabled) {
        const form = findByData('data-editor', id);
        const textarea = findByData('data-preview', id);
        if (!form) {
            return;
        }

        setEnabledUi(id, enabled, 'Saving...', false);

        const body = new URLSearchParams(new FormData(form));
        body.set('ajax', '1');
        body.set('integration_id', id);
        body.set('enabled', enabled ? '1' : '0');
        body.set('prompt_template', textarea ? textarea.value : '');

        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'fetch'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'save_failed');
                }
                return payload;
            });
        }).then(function () {
            setEnabledUi(id, enabled, 'Saved', false);
        }).catch(function () {
            setEnabledUi(id, !enabled, 'Save failed', true);
        });
    }

    document.querySelectorAll('.cc-list-button').forEach(function (button) {
        button.addEventListener('click', function () {
            activateEditor(button.getAttribute('data-target') || '');
        });
    });

    document.querySelectorAll('[data-enable-toggle]').forEach(function (toggle) {
        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
        });
        toggle.addEventListener('change', function () {
            const id = toggle.getAttribute('data-enable-toggle') || '';
            saveEnabled(id, toggle.checked);
        });
    });

    document.querySelectorAll('.cc-prompt').forEach(function (textarea) {
        textarea.addEventListener('input', function () {
            updatePreview(textarea.getAttribute('data-preview') || '');
        });
        updatePreview(textarea.getAttribute('data-preview') || '');
    });

    document.querySelectorAll('[data-insert]').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = button.getAttribute('data-for') || '';
            const textarea = findByData('data-preview', id);
            if (textarea) {
                insertAtCursor(textarea, button.getAttribute('data-insert') || '');
            }
        });
    });

    document.querySelectorAll('[data-reset]').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = button.getAttribute('data-reset') || '';
            const textarea = findByData('data-preview', id);
            if (textarea) {
                textarea.value = defaultPrompts[id] || '';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    });
})();
</script>
</body>
</html>
