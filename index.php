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

    if (chimCustomSetIntegrationSettings($integrationId, $enabled, $promptTemplate)) {
        $message = 'Saved integration settings.';
    } else {
        $error = 'Could not save settings. Run the CHIM-Custom plugin installer migrations first.';
    }
}

$integrations = chimCustomGetIntegrations();
$states = chimCustomGetRecentStates(100);
$heartbeat = chimCustomGetHeartbeat();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CHIM-Custom</title>
    <link rel="stylesheet" href="../../ui/css/main.css">
    <style>
        body { padding: 24px; background: #202020; color: #f8f9fa; }
        .cc-wrap { max-width: 1180px; margin: 0 auto; }
        .cc-panel { border: 1px solid #3a3a3a; background: #2a2a2a; border-radius: 8px; padding: 18px; margin-bottom: 18px; }
        .cc-grid { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(320px, 1.3fr); gap: 16px; }
        .cc-muted { color: #bbb; }
        .cc-ok { color: #88d18a; }
        .cc-warn { color: #ffbf66; }
        textarea { width: 100%; min-height: 82px; background: #151515; color: #f8f9fa; border: 1px solid #444; border-radius: 6px; padding: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #3a3a3a; padding: 10px; text-align: left; vertical-align: top; }
        th { color: rgb(242, 124, 17); }
        code { color: #ddd; }
        @media (max-width: 860px) { .cc-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main class="cc-wrap">
    <div class="cc-panel">
        <h1>CHIM-Custom</h1>
        <p class="cc-muted">Optional prompt support for third-party Skyrim mods. This plugin stores detected custom mod state and injects short visible-state lines into CHIM prompts.</p>
        <?php if (!chimCustomDbReady()): ?>
            <p class="cc-warn">Database tables are not installed yet. Install or update CHIM-Custom through Server Plugins so migrations run.</p>
        <?php endif; ?>
        <?php if ($message !== ''): ?><p class="cc-ok"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="cc-warn"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    </div>

    <div class="cc-grid">
        <section class="cc-panel">
            <h2>Native Plugin</h2>
            <?php if ($heartbeat): ?>
                <p><strong>Status:</strong> <span class="cc-ok">Seen</span></p>
                <p><strong>Version:</strong> <?php echo htmlspecialchars((string) ($heartbeat['plugin_version'] ?? '')); ?></p>
                <p><strong>Last Update:</strong> <?php echo htmlspecialchars((string) ($heartbeat['updated_at'] ?? '')); ?></p>
            <?php else: ?>
                <p><strong>Status:</strong> <span class="cc-warn">Not seen yet</span></p>
                <p class="cc-muted">Install the CHIM-Custom Skyrim plugin and load a save. It will post a heartbeat when it starts detecting state.</p>
            <?php endif; ?>
        </section>

        <section class="cc-panel">
            <h2>Prompt Template Variables</h2>
            <p class="cc-muted">Leave the template empty for the default clinical wording. Available variables:</p>
            <p><code>{subject}</code>, <code>{status}</code>, <code>{dirt_level}</code>, <code>{blood_level}</code>, <code>{dirt_description}</code>, <code>{blood_description}</code></p>
        </section>
    </div>

    <section class="cc-panel">
        <h2>Integrations</h2>
        <?php foreach ($integrations as $integration): ?>
            <form method="post" class="cc-panel">
                <input type="hidden" name="integration_id" value="<?php echo htmlspecialchars($integration['integration_id']); ?>">
                <h3><?php echo htmlspecialchars($integration['display_name']); ?></h3>
                <p class="cc-muted"><?php echo htmlspecialchars($integration['description']); ?></p>
                <label>
                    <input type="checkbox" name="enabled" value="1" <?php echo !empty($integration['enabled']) ? 'checked' : ''; ?>>
                    Enabled
                </label>
                <p><label>Prompt Template</label></p>
                <textarea name="prompt_template" placeholder="{subject} is {dirt_description} and {blood_description}."><?php echo htmlspecialchars((string) ($integration['prompt_template'] ?? '')); ?></textarea>
                <p><button type="submit" class="btn-base btn-save">Save</button></p>
            </form>
        <?php endforeach; ?>
    </section>

    <section class="cc-panel">
        <h2>Recent State</h2>
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
                    <td><?php echo htmlspecialchars(($row['actor_name'] ?? '') . ' (' . ($row['actor_type'] ?? '') . ')'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($row['integration_id'] ?? '')); ?></td>
                    <td><code><?php echo htmlspecialchars(chimCustomJsonEncode($row['state'] ?? [])); ?></code></td>
                    <td><?php echo htmlspecialchars((string) ($row['updated_at'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>

