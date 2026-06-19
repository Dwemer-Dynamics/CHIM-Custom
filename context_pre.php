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
$blocks = [
    'chim_custom.cleaniness' => chimCustomBuildCurrentCharacterCleaninessBlock($npcName, $playerName),
    'chim_custom.survival' => chimCustomBuildCurrentCharacterSurvivalBlock($npcName, $playerName),
];

$blocks = array_filter($blocks, static function ($block) {
    return trim((string) $block) !== '';
});

if (function_exists('chimRegisterPromptInjection')) {
    foreach ($blocks as $id => $block) {
        chimRegisterPromptInjection('character_bottom', $id, $block, 50);
    }
    return;
}

if (empty($blocks)) {
    return;
}

if (!isset($GLOBALS['HERIKA_PERS'])) {
    $GLOBALS['HERIKA_PERS'] = '';
}
$GLOBALS['HERIKA_PERS'] .= "\n" . implode("\n", $blocks);
