<?php

namespace App\Jobs;

use App\Services\VisitorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StoreVisitationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(private Request $request)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        VisitorService::new()->storeVisitor($this->request);
    }
}
