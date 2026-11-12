<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Models\ConsultationSlot;
use App\Services\ConsultationBilling;
use Illuminate\Console\Command;

class ExpireConsultationHolds extends Command
{
    protected $signature = 'platform:expire-consultations';

    protected $description = 'Release expired unpaid consultation reservations without deleting their history';

    public function handle(ConsultationBilling $billing): int
    {
        ConsultationSlot::whereIn('id', Consultation::where('status', 'pending_payment')->where('expires_at', '<=', now())->select('consultation_slot_id'))->chunkById(100, function ($items) use ($billing) {
            foreach ($items as $item) {
                $billing->expire($item->id);
            }
        });

        return self::SUCCESS;
    }
}
