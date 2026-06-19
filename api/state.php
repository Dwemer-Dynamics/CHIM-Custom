<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');

$enginePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'runtime_bootstrap.php';
chimRuntimeBootstrap($enginePath, [
    'load_general_settings' => true,
    'load_stt_connector' => false,
    'load_itt_connector' => false,
    'load_player_name' => false,
    'load_narrator' => false,
]);
$GLOBALS['db'] = $GLOBALS['db'] ?? new sql();

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_custom.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo chimCustomJsonEncode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo chimCustomJsonEncode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

if (!chimCustomDbReady()) {
    http_response_code(503);
    echo chimCustomJsonEncode(['ok' => false, 'error' => 'database_not_ready']);
    exit;
}

chimCustomRecordHeartbeat($payload);

$saved = 0;
$states = $payload['states'] ?? [];
if (!is_array($states)) {
    $states = [];
}

foreach ($states as $statePayload) {
    if (!is_array($statePayload)) {
        continue;
    }
    if (chimCustomUpsertActorState($statePayload)) {
        $saved++;
    }
}

echo chimCustomJsonEncode([
    'ok' => true,
    'saved' => $saved,
]);

