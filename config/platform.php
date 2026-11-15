<?php

return [
    'finance' => ['transfer_destination' => env('MANUAL_PAYMENT_DESTINATION'), 'transfer_holder' => env('MANUAL_PAYMENT_HOLDER'), 'receipt_hold_hours' => (int) env('RECEIPT_REVIEW_HOLD_HOURS', 24)],
    'learning' => ['embed_hosts' => array_filter(explode(',', env('ARVAN_EMBED_HOSTS', 'player.arvancloud.ir,player.arvanvod.ir'))), 'skyroom_hosts' => array_filter(explode(',', env('SKYROOM_HOSTS', 'skyroom.online,skyroom.ir')))],
    'reminders' => ['enabled' => (bool) env('EVENT_REMINDERS_ENABLED', true), 'lead_minutes' => (int) env('EVENT_REMINDER_LEAD_MINUTES', 60)],
    'sms' => ['driver' => env('SMS_DRIVER', 'disabled'), 'melipayamak' => ['mode' => env('MELIPAYAMAK_MODE', 'rest'), 'username' => env('MELIPAYAMAK_USERNAME'), 'api_key' => env('MELIPAYAMAK_API_KEY'), 'otp_body_id' => env('MELIPAYAMAK_OTP_BODY_ID'), 'invitation_body_id' => env('MELIPAYAMAK_INVITATION_BODY_ID'), 'invitations_enabled' => (bool) env('MELIPAYAMAK_INVITATIONS_ENABLED', false), 'delivery_enabled' => (bool) env('MELIPAYAMAK_DELIVERY_ENABLED', false), 'reminders_enabled' => (bool) env('MELIPAYAMAK_REMINDERS_ENABLED', false), 'reminder_body_id' => env('MELIPAYAMAK_REMINDER_BODY_ID')]],
    'zibal' => ['merchant' => env('ZIBAL_MERCHANT'), 'enabled' => env('ZIBAL_ENABLED', false)],
];
