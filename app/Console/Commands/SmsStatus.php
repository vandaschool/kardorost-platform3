<?php

namespace App\Console\Commands;

use App\Services\MelipayamakClient;
use Illuminate\Console\Command;

class SmsStatus extends Command
{
    protected $signature = 'platform:sms-status';

    protected $description = 'Inspect SMS configuration readiness without exposing secrets or sending messages';

    public function handle(MelipayamakClient $client): int
    {
        $driver = config('platform.sms.driver');
        $mode = config('platform.sms.melipayamak.mode');
        $enabled = (bool) config('platform.sms.melipayamak.invitations_enabled');
        $this->table(['Setting', 'State'], [
            ['Driver', in_array($driver, ['disabled', 'log', 'melipayamak'], true) ? $driver : 'unsupported'],
            ['Melipayamak mode', in_array($mode, ['rest', 'console'], true) ? $mode : 'unsupported'],
            ['OTP configuration ready', $client->configured(config('platform.sms.melipayamak.otp_body_id')) ? 'yes' : 'no'],
            ['Invitation SMS enabled', $enabled ? 'yes' : 'no'],
            ['Invitation configuration ready', $enabled && $client->configured(config('platform.sms.melipayamak.invitation_body_id')) ? 'yes' : 'no'],
        ]);
        $this->line('Configuration check only. No message sent; live delivery has not been verified.');

        return self::SUCCESS;
    }
}
