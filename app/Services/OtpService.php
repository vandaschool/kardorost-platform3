<?php

namespace App\Services;

use App\Models\OtpChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function __construct(private SmsSender $sender) {}

    public function issue(string $phone, string $purpose): OtpChallenge
    {
        $code = (string) random_int(10000, 99999);
        $this->sender->sendCode($phone, $code);

        return DB::transaction(function () use ($phone, $purpose, $code) {
            OtpChallenge::where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);

            return OtpChallenge::create(['phone' => $phone, 'purpose' => $purpose, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(2)]);
        });
    }

    public function consume(int $id, string $phone, string $purpose, string $code): bool
    {
        return DB::transaction(function () use ($id, $phone, $purpose, $code) {
            $challenge = OtpChallenge::whereKey($id)->lockForUpdate()->first();
            if (! $challenge || $challenge->phone !== $phone || $challenge->purpose !== $purpose || $challenge->consumed_at || $challenge->expires_at->isPast() || $challenge->attempts >= 5) {
                return false;
            }
            $challenge->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                return false;
            }
            $challenge->update(['consumed_at' => now()]);

            return true;
        });
    }
}
