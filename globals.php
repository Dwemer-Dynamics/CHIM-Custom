<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_custom.php';

if (function_exists('chimCustomRegisterPromptHooks')) {
    chimCustomRegisterPromptHooks();
}
