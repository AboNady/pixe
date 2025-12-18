<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Mail\PostCreated;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use App\Http\Requests\UpdateJobRequest; 

class JobController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        // 1. Get Featured Jobs separately (Not paginated)
        $featuredJobs = Cache::remember('featured_jobs', 3600, function () {
                    return Job::with(['employer', 'tags'])
                        ->where('is_featured', true)
                        ->latest()
                        ->take(8)
                        ->get();
                });

        // 2. Get All Jobs (Paginated)
        $jobs = Job::with(['employer', 'tags'])
                    ->latest()
                    ->simplePaginate(10); 
        
                return view('main.index', [
                    'jobs' => $jobs,
                    'featuredJobs' => $featuredJobs,
                    'tags' => Cache::remember('tags_list', 3600, fn() => Tag::all())
                ]);
    }
    public function create()
    {
        $tags = Cache::remember('tags_list', 3600, fn() => Tag::all());

        return view('jobs.create', ['tags' => $tags]);
    }

    public function store(Request $request)
    {
        // 1. Validate request input directly here
        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'required|string',
            'location'      => 'required|string|max:255',
            'salary'        => 'required|string|max:255',
            'type'          => 'required|string|in:Full-time,Part-time,Contract,Remote',
            'posted_date'   => 'required|date',
            'closing_date'  => 'required|date|after_or_equal:posted_date',
            'url'           => 'nullable|url|max:255',
            'is_featured'   => 'sometimes|boolean',
            'logo'          => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'tags'          => 'sometimes|array',
            'tags.*'        => 'integer|exists:tags,id',
        ]);
        
        $user = Auth::user();

        // Handle File Upload
        $logoPath = $request->file('logo')->store('logos', 'public');

        $job = Job::create([
            'employer_id'  => $user->employer->id,
            'title'        => $validated['title'],
            'description'  => $validated['description'],
            'location'     => $validated['location'],
            'salary'       => $validated['salary'],
            'type'         => $validated['type'],
            'posted_date'  => $validated['posted_date'],
            'closing_date' => $validated['closing_date'],
            'url'          => $validated['url'] ?? null,
            'logo'         => $logoPath,
            'is_featured'  => $validated['is_featured'] ?? false,
        ]);

        if (! empty($validated['tags'])) {
            $job->tags()->sync($validated['tags']);
        }

        // PERFORMANCE FIX 4: Queue the email
        Mail::to($user->email)->queue(new PostCreated($user, $job));
        // In store() and update()
        Cache::forget('featured_jobs'); // Force the homepage to refresh next time
        return redirect()
            ->route('index')
            ->with('success', 'Job posted successfully!');
    }

    public function show(Job $job)
    {
        // PERFORMANCE FIX 5: Lazy Eager Loading
        $job->load(['employer', 'tags']);

        return view('jobs.show', ['job' => $job]);
    }

    public function edit(Job $job)
    {
        $tags = Cache::remember('tags_list', 3600, fn() => Tag::all());
        
        return view('jobs.update', [
            'job'  => $job,
            'tags' => $tags,
        ]);
    }
public function update(Request $request, Job $job)
    {
        // 0. Authorization Check
        // Ensure the logged-in user owns this job (via their employer profile)
        if ($request->user()->employer->id !== $job->employer_id) {
            abort(403, 'Unauthorized action.');
        }

        // 1. Validate request input directly here
        // Note: 'logo' is now 'nullable' so users aren't forced to re-upload it
        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'required|string',
            'location'      => 'required|string|max:255',
            'salary'        => 'required|string|max:255',
            'type'          => 'required|string|in:Full-time,Part-time,Contract,Remote',
            'posted_date'   => 'required|date',
            'closing_date'  => 'required|date|after_or_equal:posted_date',
            'url'           => 'nullable|url|max:255',
            'is_featured'   => 'sometimes|boolean',
            'logo'          => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'tags'          => 'sometimes|array',
            'tags.*'        => 'integer|exists:tags,id',
        ]);

        // 2. Handle File Upload
        // Start with the existing logo path
        $logoPath = $job->logo;

        // If a new file was uploaded, store it and update the path
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('logos', 'public');
        }

        // 3. Update the Job Record
        $job->update([
            'title'        => $validated['title'],
            'description'  => $validated['description'],
            'location'     => $validated['location'],
            'salary'       => $validated['salary'],
            'type'         => $validated['type'],
            'posted_date'  => $validated['posted_date'],
            'closing_date' => $validated['closing_date'],
            'url'          => $validated['url'] ?? null,
            'logo'         => $logoPath, // Uses new path OR keeps the old one
            'is_featured'  => $validated['is_featured'] ?? false,
        ]);

        // 4. Sync Tags
        // usage of isset() checks if the user actually sent the tags field
        if (isset($validated['tags'])) {
            $job->tags()->sync($validated['tags']);
        }
        Cache::forget('featured_jobs'); // Force the homepage to refresh next time
        // 5. Return Response
        return redirect()
            ->route('jobs.manage')
            ->with('success', 'Job updated successfully!');
    }

    public function destroy(Job $job)
    {
        $this->authorize('delete', $job);
        
        $job->delete();
        Cache::forget('featured_jobs'); // Force the homepage to refresh next time
        return redirect()
               ->route('jobs.manage')
               ->with('success', 'Job deleted successfully.');
    }
}
