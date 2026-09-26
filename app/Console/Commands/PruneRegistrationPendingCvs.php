<?php

namespace App\Console\Commands;

use App\Services\RegistrationPendingCvService;
use Illuminate\Console\Command;

class PruneRegistrationPendingCvs extends Command
{
    protected $signature = 'registration:prune-pending-cvs';

    protected $description = 'Xóa các file CV đăng ký giảng viên tạm đã hết hạn.';

    public function handle(RegistrationPendingCvService $pendingCvs): int
    {
        $deleted = $pendingCvs->pruneExpired();
        $this->info("Đã xóa {$deleted} CV đăng ký tạm hết hạn.");

        return self::SUCCESS;
    }
}
