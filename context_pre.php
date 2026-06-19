<?php

if (empty($GLOBALS['ENGINE_PATH'])) {
    return;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_custom.php';

if (!chimCustomDbReady()) {
    return;
}

chimCustomRegisterPromptHooks();

$npcName = trim((string) ($GLOBALS['HERIKA_NAME'] ?? ''));
$playerName = trim((string) ($GLOBALS['PLAYER_NAME'] ?? 'Player'));
$cleaninessBlock = chimCustomBuildCurrentCharacterCleaninessBlock($npcName, $playerName);

if ($cleaninessBlock === '') {
    return;
}

if (function_exists('chimRegisterPromptInjection')) {
    chimRegisterPromptInjection('character_bottom', 'chim_custom.cleaniness', $cleaninessBlock, 50);
    return;
}

if (!isset($GLOBALS['HERIKA_PERS'])) {
    $GLOBALS['HERIKA_PERS'] = '';
}
$GLOBALS['HERIKA_PERS'] .= "\n" . $cleaninessBlock;
