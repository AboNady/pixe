<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\VectorService;
use App\Models\Job;

class ChatController extends Controller
{
    /**
     * Define the intents, patterns, and responses in one place.
     */
    private const INTENT_CONFIG = [
        'greeting' => [
            'keywords' => ['helloo', 'hii', 'heey', 'greetings', 'sup', 'you', 'welcome'],
            'threshold' => 1,
            'response' => "**Hello!** 👋 I am your AI Recruiter.\n\nTry asking:\n- *\"High paying Laravel jobs\"*\n- *\"Remote React roles\"*",
        ],
        'gratitude' => [
            'keywords' => ['thanks', 'thank', 'thx', 'cool', 'awesome', 'great', 'ok'],
            'threshold' => 1,
            'response' => "You're very welcome! 🚀 Let me know if you need anything else.",
        ],
        'identity' => [
            'keywords' => ['who', 'bot', 'human', 'ai', 'real', 'name', 'developer'],
            'threshold' => 1,
            'response' => "I am **Pixel AI**, a smart recruiting agent built with **Laravel 11** and **Groq**.",
        ],
        'help' => [
            'keywords' => ['help', 'support', 'guide', 'stuck', 'error', 'broken'],
            'threshold' => 2,
            'response' => "Here is what I can do:\n🔹 Salary Search\n🔹 Tech Stack Search\n🔹 Freshness Filter",
        ]
    ];

    public function __invoke(Request $request, VectorService $vectorService)
    {
        $startTime = microtime(true);
        $request->validate(['question' => 'required|string|max:1000']);
        
        $question = trim($request->input('question'));
        
        // GROQ CONFIGURATION
        $groqKey = env('GROQ_API_KEY'); 
        $groqEndpoint = "https://api.groq.com/openai/v1/chat/completions";
        $model = "llama-3.1-8b-instant"; 

        // --- LAYER 0: LOCAL FILTER ---
        // We check if we have a match. If yes, we return immediately.
        if ($localMatch = $this->checkLocalIntent($question)) {
            return response()->json([
                'answer'   => $localMatch['answer'],
                'actions'  => $localMatch['actions'], // We now support buttons!
                'duration' => round(microtime(true) - $startTime, 2)
            ]);
        }

        // --- STEP 1: ASK THE AI ROUTER ---
        
        $routerSystem = <<<EOT
You are a database query router.
Return ONLY valid JSON. No markdown.

Tools:
1. "sql_salary": questions about highest/lowest pay, salary sort.
2. "sql_recent": questions about new, latest, recent jobs.
3. "vector_search": everything else (complex skills, fuzzy descriptions).

Schema:
{
  "tool": "sql_salary" | "sql_recent" | "vector_search",
  "sort": "asc" | "desc",
  "limit": 5,
  "search_term": null | "string"
}

INSTRUCTIONS:
- If user mentions a specific technology or title (e.g. "Laravel", "Manager", "Vue"), put it in "search_term".
- If no specific tech is mentioned, set "search_term": null.
- Extract "limit" if user asks for a number ("top 3"). Default 5.
EOT;

        try {
            $routerResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $groqKey,
                'Content-Type'  => 'application/json',
            ])->post($groqEndpoint, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $routerSystem],
                    ['role' => 'user', 'content' => $question]
                ],
                'temperature' => 0 // Strict for JSON
            ]);

            $rawContent = $routerResponse->json()['choices'][0]['message']['content'] ?? '{}';
            
            if (preg_match('/\{.*\}/s', $rawContent, $matches)) {
                $decision = json_decode($matches[0], true);
            } else {
                $decision = [];
            }
            
            $tool = $decision['tool'] ?? 'vector_search';
            $limit = isset($decision['limit']) ? min(max((int)$decision['limit'], 1), 20) : 5;
            $searchTerm = $decision['search_term'] ?? null;

        } catch (\Exception $e) {
            $tool = 'vector_search';
            $limit = 15;
            $searchTerm = null;
        }

        // --- STEP 2: EXECUTE THE CHOSEN TOOL ---

        $context = "";

        if ($tool === 'sql_salary') {
            $direction = ($decision['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            
            $query = Job::with(['employer', 'tags'])
                ->orderByRaw('CAST(REPLACE(REPLACE(salary, " EGP", ""), ",", "") AS UNSIGNED) ' . $direction);

            if ($searchTerm) {
                $query->where('title', 'like', "%{$searchTerm}%");
            }

            $jobs = $query->take($limit)->get();
                
            $context = "Strict database result for salary sort" . ($searchTerm ? " (Filter: $searchTerm)" : "") . ":\n";
            foreach($jobs as $j) {
                $tags = $j->tags->pluck('name')->implode(', ');
                $context .= "- Role: {$j->title} | Location: {$j->location} |Company: {$j->employer->name} | Pay: {$j->salary} | Tags: [{$tags}]\n";
            }

        } elseif ($tool === 'sql_recent') {
            
            $query = Job::with(['employer', 'tags'])->latest();

            if ($searchTerm) {
                $query->where('title', 'like', "%{$searchTerm}%");
            }

            $jobs = $query->take($limit)->get();
            
            $context = "Strict database result for recent jobs" . ($searchTerm ? " (Filter: $searchTerm)" : "") . ":\n";
            foreach($jobs as $j) {
                $tags = $j->tags->pluck('name')->implode(', ');
                $context .= "- Role: {$j->title} | Location: {$j->location} | Company: {$j->employer->name} | Posted: {$j->created_at->diffForHumans()} | Tags: [{$tags}]\n";
            }

        } else {
            // VECTOR SEARCH
            $matches = $vectorService->search($question);
            $jobIds = array_column($matches, 'id');

            if (count($jobIds) > 0) {
                $jobs = Job::with(['employer', 'tags'])
                    ->whereIn('id', $jobIds)
                    ->take($limit)
                    ->get();

                $context = "Here are the most relevant jobs found:\n\n";
                foreach ($jobs as $job) {
                    $tagString = $job->tags->pluck('name')->implode(', ');
                    $context .= "JOB ID: {$job->id}\nTITLE: {$job->title}\nCOMPANY: {$job->employer->name}\nLOCATION: {$job->location}\nSALARY: {$job->salary}\nTAGS: {$tagString}\nDESCRIPTION: " . Str::limit($job->description, 600) . "\n-----------------------------------\n";
                }
            } else {
                $context = "No relevant jobs found in the database.";
            }
        }

        // --- STEP 3: FINAL AI ANSWER ---
        
        $finalSystem = "You are an expert Career Coach. Answer using ONLY the job data provided. If no jobs are listed, politely say so. Use bullet points.";
        $finalUser = "JOB DATA:\n{$context}\n\nUSER QUESTION:\n{$question}";

        try {
            $finalResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $groqKey,
                'Content-Type'  => 'application/json',
            ])->post($groqEndpoint, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $finalSystem],
                    ['role' => 'user', 'content' => $finalUser]
                ],
                'temperature' => 0.7
            ]);

            $answer = $finalResponse->json()['choices'][0]['message']['content'] ?? 'Sorry, Groq could not answer.';

        } catch (\Exception $e) {
            $answer = "Connection Error: " . $e->getMessage();
        }
        
        $duration = round(microtime(true) - $startTime, 2);

        return response()->json([
            'answer' => $answer,
            'duration' => $duration,
        ]);
    }

    // --------------------------------------------------------
    // FIXED LOCAL INTENT FUNCTION (No $startTime issues)
    // --------------------------------------------------------
    /**
     * @return array{answer: string, actions: array}|null
     */
    private function checkLocalIntent(string $question): ?array
    {
        $clean = strtolower(trim($question));
        $clean = preg_replace('/[^a-z0-9\s]/', '', $clean);
        $userWords = explode(' ', $clean);

        foreach (self::INTENT_CONFIG as $intent => $config) {
            foreach ($userWords as $userWord) {
                
                if (strlen($userWord) < 2) continue;

                foreach ($config['keywords'] as $keyword) {
                    
                    // A. Exact Match
                    if ($userWord === $keyword) {
                        return [
                            'answer' => $config['response'],
                            'actions' => $config['actions'] ?? []
                        ];
                    }

                    // B. Fuzzy Match
                    if ($config['threshold'] > 0) {
                        $distance = levenshtein($userWord, $keyword);
                        if ($distance <= $config['threshold']) {
                            return [
                                'answer' => $config['response'],
                                'actions' => $config['actions'] ?? []
                            ];
                        }
                    }
                }
            }
        }

        return null;
    }
}
