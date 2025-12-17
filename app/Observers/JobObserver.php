<?php

namespace App\Observers;

use App\Models\Job;
use App\Services\VectorService;
// "ShouldHandleEventsAfterCommit" ensures we only talk to AI 
// AFTER the job is safely saved in MySQL.
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit; 

class JobObserver implements ShouldHandleEventsAfterCommit
{
    protected $vectorService;

    // Laravel automatically injects your VectorService here
    public function __construct(VectorService $vectorService)
    {
        $this->vectorService = $vectorService;
    }

    /**
     * Handle the Job "created" event.
     */
    public function created(Job $job): void
    {
        // New job in SQL? -> Send it to Pinecone immediately.
        $this->vectorService->upsertJob($job);
    }

    /**
     * Handle the Job "updated" event.
     */
    public function updated(Job $job): void
    {
        // Changed salary or description? -> Update Pinecone.
        $this->vectorService->upsertJob($job);
    }


}