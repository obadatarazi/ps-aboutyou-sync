<?php

return [
    'app_name' => 'PrestaShop AboutYou Sync',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
    'test_mode' => filter_var($_ENV['TEST_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'dry_run' => filter_var($_ENV['DRY_RUN'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'ui_enabled' => filter_var($_ENV['UI_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
];
