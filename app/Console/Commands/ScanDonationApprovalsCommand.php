<?php

namespace App\Console\Commands;

use App\Services\ExpiringDonationApprovalService;
use Illuminate\Console\Command;

class ScanDonationApprovalsCommand extends Command
{
    protected $signature = 'ecoloop:scan-donation-approvals';

    protected $description = 'Notify managers about near-expiry donation batches and run pre-approved donations';

    public function handle(ExpiringDonationApprovalService $approvals): int
    {
        $result = $approvals->scan();
        $this->table(['Donation scan metric', 'Result'], [
            ['Eligible batches checked', $result['batches']],
            ['Approval requests created', $result['requests_created']],
            ['Manager reminders sent', $result['reminders_sent']],
            ['Scheduled donations run', $result['scheduled_donations_run']],
        ]);

        return self::SUCCESS;
    }
}
