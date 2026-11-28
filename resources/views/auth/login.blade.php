@php($a = app(\App\Ashby\Content\ContentPack::class)->file('common')['account'])
<x-site :title="$a['sign_in']" narrow>
    <h1 class="mb-1 text-3xl font-extrabold">{{ $a['sign_in'] }}</h1>
    <p class="soft mb-5">{{ $a['sign_in_lead'] }}</p>
    @if (session('status'))
        <p class="notice">{{ session('status') }}</p>
    @endif
    <form method="POST" action="{{ route('login.store') }}" class="grid gap-4">
        @csrf
        <label class="field">{{ $a['email'] }}
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </label>
        @error('email') <p class="error">{{ $message }}</p> @enderror
        <label class="field">{{ $a['password'] }}
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        @error('password') <p class="error">{{ $message }}</p> @enderror
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember"> {{ $a['remember'] }}
        </label>
        <button class="btn btn-main" data-test="login-button">{{ $a['sign_in'] }}</button>
        <a href="{{ route('password.request') }}" class="soft text-sm underline">{{ $a['forgot'] }}</a>
    </form>
</x-site>
