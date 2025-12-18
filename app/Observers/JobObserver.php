// pixe/app/Observers/JobObserver.php

namespace App\Observers;

use App\Models\Job;
use App\Services\VectorService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit; 

class JobObserver implements ShouldHandleEventsAfterCommit
{
    // REMOVE THE CONSTRUCTOR:
    // protected $vectorService;
    // public function __construct(VectorService $vectorService) { ... }

    /**
     * Handle the Job "created" event.
     */
    public function created(Job $job, VectorService $vectorService): void // <-- Inject here
    {
        // New job in SQL? -> Send it to Pinecone immediately.
        $vectorService->upsertJob($job);
    }

    /**
     * Handle the Job "updated" event.
     */
    public function updated(Job $job, VectorService $vectorService): void // <-- Inject here
    {
        // Changed salary or description? -> Update Pinecone.
        $vectorService->upsertJob($job);
    }
}
