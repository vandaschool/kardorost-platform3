<?php

return [
    'sms' => ['driver' => env('SMS_DRIVER', 'disabled'), 'api_key' => env('SMS_API_KEY'), 'template' => env('SMS_TEMPLATE')],
    'zibal' => ['merchant' => env('ZIBAL_MERCHANT'), 'enabled' => env('ZIBAL_ENABLED', false)],
];
