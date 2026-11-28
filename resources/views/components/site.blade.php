@props(['title', 'sub' => null, 'narrow' => false])
@php($common = app(\App\Ashby\Content\ContentPack::class)->file('common'))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ $common['product'] }}</title>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" href="/favicon.svg?v=2" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="site font-sans antialiased">
    <header class="site-header">
        <a href="/" class="site-badge" aria-label="{{ $common['product'] }}">F</a>
        <div>
            <div class="font-extrabold">{{ $common['product'] }}</div>
            @if ($sub)
                <div class="soft text-[13px]">{{ $sub }}</div>
            @endif
        </div>
        @auth
            <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                @csrf
                <button class="soft cursor-pointer text-sm">{{ $common['sign_out'] }}</button>
            </form>
        @endauth
    </header>
    <main class="{{ $narrow ? 'site-narrow' : 'site-main' }}">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
