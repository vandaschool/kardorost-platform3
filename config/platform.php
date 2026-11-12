<?php

return [
    'finance' => ['transfer_destination' => env('MANUAL_PAYMENT_DESTINATION'), 'transfer_holder' => env('MANUAL_PAYMENT_HOLDER'), 'receipt_hold_hours' => (int) env('RECEIPT_REVIEW_HOLD_HOURS', 24)],
    'learning' => ['embed_hosts' => array_filter(explode(',', env('ARVAN_EMBED_HOSTS', 'player.arvancloud.ir,player.arvanvod.ir'))), 'skyroom_hosts' => array_filter(explode(',', env('SKYROOM_HOSTS', 'skyroom.online,skyroom.ir')))],
    'sms' => ['driver' => env('SMS_DRIVER', 'disabled'), 'api_key' => env('SMS_API_KEY'), 'template' => env('SMS_TEMPLATE')],
    'zibal' => ['merchant' => env('ZIBAL_MERCHANT'), 'enabled' => env('ZIBAL_ENABLED', false)],
];
