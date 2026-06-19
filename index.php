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
    $defaultPromptTemplate = trim(ccIntegrationDefaultPrompt($integrationId));
    if ($defaultPromptTemplate !== '' && $promptTemplate === $defaultPromptTemplate) {
        $promptTemplate = '';
    }

    if (chimCustomSetIntegrationSettings($integrationId, $enabled, $promptTemplate)) {
        $message = 'Saved integration settings.';
    } else {
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
            max-width: 1240px;
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

        .cc-list-button {
            appearance: none;
            border: 1px solid var(--cc-border);
            border-radius: 8px;
            background: #222;
            color: var(--cc-text);
            padding: 12px;
            text-align: left;
            cursor: pointer;
            transition: border-color 0.16s ease, background 0.16s ease;
        }

        .cc-list-button:hover,
        .cc-list-button.active {
            border-color: var(--cc-border-strong);
            background: #272727;
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

        .cc-editor {
            display: none;
        }

        .cc-editor.active {
            display: block;
        }

        .cc-editor-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: start;
            margin-bottom: 16px;
        }

        .cc-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--cc-border);
            border-radius: 6px;
            background: #1b1b1b;
            padding: 8px 10px;
        }

        .cc-form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.9fr);
            gap: 16px;
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
            border: 1px solid var(--cc-border);
            background: var(--cc-panel-soft);
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 12px;
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
            .cc-page-head,
            .cc-editor-head {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<main class="cc-wrap">
    <section class="cc-panel cc-page-head">
        <div>
            <h1>CHIM-Custom</h1>
            <p class="cc-muted">Optional prompt support for third-party Skyrim mods. Detected custom state is stored by integration and can be rendered into CHIM prompts.</p>
            <div class="cc-badge-row">
                <?php if ($heartbeat): ?>
                    <span class="cc-badge ok">Native plugin seen</span>
                    <span class="cc-badge">Version <?php echo ccH($heartbeat['plugin_version'] ?? ''); ?></span>
                    <span class="cc-badge">Updated <?php echo ccH($heartbeat['updated_at'] ?? ''); ?></span>
                <?php else: ?>
                    <span class="cc-badge warn">Native plugin not seen</span>
                <?php endif; ?>
                <?php if (chimCustomDbReady()): ?>
                    <span class="cc-badge ok">Database ready</span>
                <?php else: ?>
                    <span class="cc-badge warn">Database tables missing</span>
                <?php endif; ?>
            </div>
            <?php if (!chimCustomDbReady()): ?>
                <p class="cc-message warn">Database tables are not installed yet. Install or update CHIM-Custom through Server Plugins so migrations run.</p>
            <?php endif; ?>
            <?php if ($message !== ''): ?><p class="cc-message"><?php echo ccH($message); ?></p><?php endif; ?>
            <?php if ($error !== ''): ?><p class="cc-message warn"><?php echo ccH($error); ?></p><?php endif; ?>
        </div>
        <div class="cc-badge accent">Prompt-only state</div>
    </section>

    <section class="cc-panel">
        <h2>Prompt Manager</h2>
        <div class="cc-manager">
            <aside>
                <div class="cc-list" role="tablist" aria-label="CHIM-Custom integrations">
                    <?php foreach ($integrations as $index => $integration): ?>
                        <?php
                        $integrationId = (string) ($integration['integration_id'] ?? '');
                        $isActive = $integrationId === $firstIntegrationId;
                        $hasCustomPrompt = trim((string) ($integration['prompt_template'] ?? '')) !== '';
                        ?>
                        <button
                            type="button"
                            class="cc-list-button <?php echo $isActive ? 'active' : ''; ?>"
                            data-target="<?php echo ccH($integrationId); ?>"
                            role="tab"
                            aria-selected="<?php echo $isActive ? 'true' : 'false'; ?>"
                        >
                            <span class="cc-list-title">
                                <span><?php echo ccH($integration['display_name'] ?? $integrationId); ?></span>
                            </span>
                            <span class="cc-list-meta">
                                <span class="cc-badge <?php echo !empty($integration['enabled']) ? 'ok' : 'warn'; ?>"><?php echo ccH(ccIntegrationStatus($integration)); ?></span>
                                <span class="cc-badge"><?php echo $hasCustomPrompt ? 'Custom prompt' : 'Default prompt'; ?></span>
                            </span>
                        </button>
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
                    ?>
                    <form method="post" class="cc-editor <?php echo $isActive ? 'active' : ''; ?>" data-editor="<?php echo ccH($integrationId); ?>">
                        <input type="hidden" name="integration_id" value="<?php echo ccH($integrationId); ?>">
                        <div class="cc-editor-head">
                            <div class="cc-editor-title">
                                <h3><?php echo ccH($integration['display_name'] ?? $integrationId); ?></h3>
                                <p class="cc-muted"><?php echo ccH($integration['description'] ?? ''); ?></p>
                            </div>
                            <label class="cc-toggle">
                                <input type="checkbox" name="enabled" value="1" <?php echo !empty($integration['enabled']) ? 'checked' : ''; ?>>
                                Enabled
                            </label>
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
        <h2>Recent State</h2>
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
        document.querySelectorAll('.cc-list-button').forEach(function (button) {
            const active = button.getAttribute('data-target') === id;
            button.classList.toggle('active', active);
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

    document.querySelectorAll('.cc-list-button').forEach(function (button) {
        button.addEventListener('click', function () {
            activateEditor(button.getAttribute('data-target') || '');
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
