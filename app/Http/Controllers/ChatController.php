<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\VectorService;
use App\Models\Job;

class ChatController extends Controller
{


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
        if ($localReply = $this->checkLocalIntent($question)) {
            $duration = round(microtime(true) - $startTime, 2);
            return response()->json(['answer' => $localReply, 'duration' => $duration ]);
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

Example 1: "High paying Laravel jobs" -> {"tool": "sql_salary", "sort": "desc", "limit": 5, "search_term": "Laravel"}
Example 2: "Newest React roles" -> {"tool": "sql_recent", "limit": 5, "search_term": "React"}
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
            $limit = 5;
            $searchTerm = null;
        }

        // --- STEP 2: EXECUTE THE CHOSEN TOOL ---

        $context = "";

        if ($tool === 'sql_salary') {
            $direction = ($decision['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            
            // Start Query
            $query = Job::with(['employer', 'tags'])
                ->orderByRaw('CAST(REPLACE(REPLACE(salary, " EGP", ""), ",", "") AS UNSIGNED) ' . $direction);

            // Apply Keyword Filter if exists
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
            
            // Start Query
            $query = Job::with(['employer', 'tags'])->latest();

            // Apply Keyword Filter if exists
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
            // Note: Vector search naturally handles the keyword via similarity, so we don't need a WHERE clause here usually.
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



    /**
     * ULTRAMAX Local Filter: Catches ~99% of non-job queries locally.
     * Contains massive arrays to cover typos, slang, and international variations.
     */
    private function checkLocalIntent(string $question): ?string
    {
        // 1. Sanitize: Lowercase, remove special chars (keep spaces/digits), trim
        // This converts "Hello!!!" -> "hello", "H3Y" -> "h3y", "w@ts up" -> "wts up"
        $raw = strtolower(trim($question));
        $clean = preg_replace('/[^a-z0-9 ]/', '', $raw);

        // ----------------------------------------------------------------------
        // CATEGORY 1: GREETINGS (Massive Array ~300 variations)
        // ----------------------------------------------------------------------
        $exactGreetings = [
            // Standard English
            'hi', 'hello', 'hey', 'heya', 'hiya', 'howdy', 'greetings', 'welcome', 'yo', 'sup',
            'hullo', 'holla', 'aloha', 'gday', 'hi there', 'hello there', 'hey there',
            'good morning', 'good afternoon', 'good evening', 'good night', 'good day',

            // Slang / Text Speak
            'sup', 'supp', 'suppp', 'suup', 'ssup', 'wassup', 'wazzup', 'whazzup', 'waddup',
            'wuddup', 'whatsup', 'watsup', 'wazzap', 'wussup', 'wusup', 'wasup',
            'yo', 'yoo', 'yooo', 'yoooo', 'oy', 'oi', 'ay', 'ayy', 'ayyy', 'eyo', 'yoyo',
            'wlcom', 'welcom', 'welcm', 'wlcm', 'welc', 'wc', 'hm', 'hmm', 'hmmm',
            
            // Typos (Keyboard proximity errors)
            'helo', 'hlo', 'hll', 'hlelo', 'jello', 'yello', 'ello', 'elo', 'allo', 'alo',
            'hy', 'hye', 'hie', 'hsi', 'hii', 'hiii', 'hiiii', 'heyy', 'heyyy', 'heyyyy',
            'hye', 'hdy', 'hru', 'hyd', 'hows', 'howzit', 'hw', 'hws',
            'gud mrng', 'gd mrng', 'gud morning', 'gd morning', 'gud', 'gd',
            
            // International (Common in tech/english chats)
            'hola', 'bonjour', 'salut', 'coucou', 'salam', 'salaam', 'selam', 'marhaba', 
            'ahlan', 'merhaba', 'hallo', 'guten', 'tag', 'moin', 'servus', 'ciao', 'salve', 
            'ola', 'oi', 'namaste', 'namaskar', 'vanakkam', 'konnichiwa', 'nihao', 'anyeong', 
            'privet', 'zdras', 'shalom', 'sawasdee', 'mabuhay', 'kia ora', 'szia', 'hej',

            // Bot Triggers / Commands / Tests
            'start', 'restart', 'begin', 'reset', 'menu', 'home', 'back', 'go', 'exit', 
            'quit', 'stop', 'test', 'ping', 'echo', 'wake', 'awake', 'boot', 'reboot',
            'sys', 'system', 'init', 'run', 'cmd', 'hello bot', 'hi bot', 'hey bot',
            'pixel', 'pixel ai', 'hey pixel', 'hi pixel', 'ok pixel', 'yo pixel',
            '1', '2', '3', 'a', 'b', 'c', 'test1', 'test2', '123', '1234'
        ];

        // Check Greetings
        if (in_array($clean, $exactGreetings) || Str::startsWith($clean, ['good mor', 'good aft', 'how are', 'whats up', 'nice to'])) {
             return "**Hello!** 👋 I am your AI Recruiter.\n\nI can help you analyze salaries, find specific tech stacks, or discover the latest job postings.\n\nTry asking:\n- *\"Show me high paying Laravel jobs\"*\n- *\"What jobs require React?\"*";
        }

        // ----------------------------------------------------------------------
        // CATEGORY 2: GRATITUDE & POSITIVE FEEDBACK (~250+ variations)
        // ----------------------------------------------------------------------
        // We use Str::contains here to catch sentences like "ok thanks for the help"
        $gratitudeKeywords = [
            // Thanks
            'thank', 'thx', 'tnx', 'thanx', 'tanks', 'tq', 'ty', 'tyvm', 'tysm', 
            'thanks', 'thankyou', 'tanku', 'tks', 'tx', 'thks', 'thnk', 'thnks',
            'merci', 'gracias', 'danke', 'arigato', 'shukran', 'obrigado', 'dhanyavad',
            'cheers', 'ta', 'bless', 'grateful', 'appreciate', 'props', 'kudos',
            
            // Positive / Agreement
            'cool', 'kewl', 'cul', 'coo', 'calm', 'fine',
            'great', 'gr8', 'gret', 'grt', 'grejt',
            'awesome', 'awsome', 'awsm', 'osm', 'awesum',
            'amazing', 'amazin', 'amzing', 'amaze',
            'good job', 'gd job', 'nice one', 'nice work', 'nj', 'gj', 'gw',
            'perfect', 'prfct', 'perf', 'perfection',
            'brilliant', 'brill', 'excellent', 'xcilent', 'superb',
            'wonderful', 'fantastic', 'fab', 'fabulous',
            'wow', 'woah', 'omg', 'lol', 'haha', 'hehe', 'xd',
            'love it', 'love this', 'lovely', 'like it',
            'ok', 'okay', 'okie', 'k', 'kk', 'okey', 'oke', 'alright', 'aight', 'ite', 
            'got it', 'understood', 'copy', 'roger', 'clear', 'done',
            'bet', 'dope', 'lit', 'fire', 'sick', 'gucci', 'solid', 'legit', 'valid',
            'sweet', 'rad', 'neat', 'n1', 'yep', 'yup', 'yeah', 'yea', 'yes'
        ];

        if (Str::contains($clean, $gratitudeKeywords)) {
            return "You're very welcome! 🚀 Let me know if you need to find more jobs.";
        }

        // ----------------------------------------------------------------------
        // CATEGORY 3: IDENTITY & EXISTENTIAL (~200+ variations)
        // ----------------------------------------------------------------------
        $identityKeywords = [
            // Who Questions
            'who are you', 'who r u', 'wo r u', 'hoo r u', 'hu r u',
            'what are you', 'wat r u', 'wht r u', 'what is this', 'wat is dis',
            'your name', 'ur name', 'call you', 'call u', 'introduce',
            
            // AI Checks
            'are you human', 'r u human', 'real person', 'real human', 'alive',
            'are you ai', 'r u ai', 'r u bot', 'are you bot', 'are you a robot',
            'artificial intelligence', 'chat gpt', 'chatgpt', 'openai', 'gemini', 'llama',
            'claude', 'copilot', 'meta', 'google', 'pixel', 'bot', 'robot', 'machine',
            'computer', 'software', 'program', 'script', 'code', 'algo', 'algorithm',
            
            // Creator Checks
            'created you', 'made you', 'built you', 'coded you', 'programmed you',
            'developer', 'creator', 'owner', 'boss', 'admin', 'god', 'father', 'mother',
            
            // Tech Stack Questions
            'how do you work', 'how does this work', 'technology', 'stack', 'tech',
            'under the hood', 'backend', 'frontend', 'database', 'engine', 'model'
        ];

        if (Str::contains($clean, $identityKeywords)) {
            return "I am **Pixel AI**, a smart recruiting agent built with **Laravel 11** and **Groq (Llama 3)**. \n\nI scan our SQL database to match you with the best engineering roles based on your natural language questions.";
        }

        // ----------------------------------------------------------------------
        // CATEGORY 4: HELP & CONFUSION (~150+ variations)
        // ----------------------------------------------------------------------
        $helpKeywords = [
            'help', 'halp', 'hlp', 'hjelp', 'sos', 'support', 'assist', 'assistance',
            'guide', 'manual', 'instruction', 'directions', 'tutorial',
            'stuck', 'confused', 'lost', 'dont understand', 'dunno', 'dont know', 'idk',
            'hard', 'difficult', 'cant find', 'cannot find',
            'what can you do', 'what do you do', 'how to use', 'how to search',
            'features', 'options', 'capabilities', 'abilities', 'functions',
            'not working', 'broken', 'error', 'bug', 'fail', 'stupid', 'dumb', 'bad',
            'useless', 'slow', 'crash', 'glitch', 'problem', 'issue', 'wrong'
        ];

        if (Str::contains($clean, $helpKeywords)) {
            return "Here is what I can do for you:\n\n" .
                   "🔹 **Salary Search:** Ask *\"Highest paying Python jobs\"*\n" .
                   "🔹 **Tech Stack:** Ask *\"Find jobs using Vue and Tailwind\"*\n" .
                   "🔹 **Freshness:** Ask *\"Jobs posted today\"*\n" .
                   "🔹 **Deep Search:** Ask *\"I need a remote backend role with good work-life balance\"*";
        }

        // If no match found, return null (Let the AI handle it)
        return null;
    }



}