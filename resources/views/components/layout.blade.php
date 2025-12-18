<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pixel Positions</title>
    <link rel="icon" href="{{ asset('logo.svg') }}" type="image/svg+xml">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500&display=swap" rel="stylesheet">

    {{-- Scripts --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>

<body class="min-h-screen flex flex-col bg-black text-white font-hanken-grotesk">

    <div class="px-10 flex-1 w-full flex flex-col">
        
        {{-- Navigation --}}
        <nav class="flex justify-between items-center py-4 border-b border-white/10">
            
            {{-- Logo --}}
            <div>
                <a href="{{ route('index') }}">
                    <img src="{{ Vite::asset('resources/images/logo.svg') }}" alt="Logo" class="h-8">
                </a>
            </div>

            {{-- Main Links --}}
            <div class="space-x-6 font-thin">
                <a href="{{ route('index') }}" class="hover:text-blue-500 transition">Jobs</a>
                <a href="{{ route('careers') }}" class="hover:text-blue-500 transition">Careers</a>
                <a href="{{ route('salaries') }}" class="hover:text-blue-500 transition">Salaries</a>
                <a href="{{ route('companies.index') }}" class="hover:text-blue-500 transition">Companies</a>
            </div>

            {{-- Auth / User Menu --}}
            <div>
                @auth
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="flex items-center gap-2 hover:bg-white/5 rounded-lg px-3 py-2 transition">
                            <img src="{{ Auth::user()->employer->logo ? asset('storage/' . Auth::user()->employer->logo) : Vite::asset('resources/images/default-avatar.png') }}" 
                                 class="w-8 h-8 rounded-full object-cover">
                            <span class="hidden sm:block text-sm font-medium">{{ Auth::user()->name }}</span>
                        </button>

                        {{-- Dropdown --}}
                        <div x-show="open" 
                             @click.outside="open = false" 
                             x-cloak 
                             class="absolute right-0 mt-2 w-48 bg-slate-900 border border-slate-700 rounded-xl shadow-lg py-2 z-50">
                            
                            <a href="{{ route('dashboard') }}" class="block px-4 py-2 text-sm hover:bg-white/5 hover:text-blue-400">Dashboard</a>
                            <a href="{{ route('jobs.create') }}" class="block px-4 py-2 text-sm hover:bg-white/5 hover:text-blue-400">Post a Job</a>
                            
                            <div class="border-t border-slate-700 my-1"></div>
                            
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-white/5">Log Out</button>
                            </form>
                        </div>
                    </div>
                @endauth

                @guest
                    <div class="space-x-4 font-thin">
                        <a href="{{ route('register') }}" class="hover:text-blue-500 transition">Sign Up</a>
                        <a href="{{ route('login') }}" class="hover:text-blue-500 transition">Log In</a>
                    </div>
                @endguest
            </div>
        </nav>

        {{-- Main Content --}}
        <main class="mt-10 max-w-[980px] mx-auto w-full flex-1">
            {{ $slot }}
        </main>

        {{-- Footer --}}
        <footer class="py-8 text-center text-sm text-slate-500">
            <p>© {{ date('Y') }} Pixel Positions. Designed by <a href="#" class="text-white hover:underline">Nady</a>.</p>
        </footer>
    </div>

</body>
</html>
