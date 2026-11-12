<?php

return [
    'learning' => ['embed_hosts' => array_filter(explode(',', env('ARVAN_EMBED_HOSTS', 'player.arvancloud.ir,player.arvanvod.ir'))), 'skyroom_hosts' => array_filter(explode(',', env('SKYROOM_HOSTS', 'skyroom.online,skyroom.ir')))],
    'sms' => ['driver' => env('SMS_DRIVER', 'disabled'), 'api_key' => env('SMS_API_KEY'), 'template' => env('SMS_TEMPLATE')],
    'zibal' => ['merchant' => env('ZIBAL_MERCHANT'), 'enabled' => env('ZIBAL_ENABLED', false)],
];
