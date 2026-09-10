<?php

return [
    'master_key' => env('SSH_MASTER_KEY'),
    'master_key_version' => env('SSH_MASTER_KEY_VERSION', 'v1'),
    'previous_master_keys' => json_decode(env('SSH_PREVIOUS_MASTER_KEYS_JSON', '{}'), true) ?: [],
    'admin_totp_key' => env('ADMIN_TOTP_KEY'),
    'admin_totp_required' => env('ADMIN_TOTP_REQUIRED', true),
    'telegram_webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'allowed_ssh_networks' => array_values(array_filter(explode(',', env('ALLOWED_SSH_NETWORKS', '')))),
];
