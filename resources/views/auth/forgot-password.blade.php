@php($a = app(\App\Ashby\Content\ContentPack::class)->file('common')['account'])
<x-site :title="$a['forgot_title']" narrow>
    <h1 class="mb-1 text-3xl font-extrabold">{{ $a['forgot_title'] }}</h1>
    <p class="soft mb-5">{{ $a['forgot_lead'] }}</p>
    @if (session('status'))
        <p class="notice">{{ session('status') }}</p>
    @endif
    <form method="POST" action="{{ route('password.email') }}" class="grid gap-4">
        @csrf
        <label class="field">{{ $a['email'] }}
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </label>
        @error('email') <p class="error">{{ $message }}</p> @enderror
        <button class="btn btn-main">{{ $a['send_link'] }}</button>
        <a href="{{ route('login') }}" class="soft text-sm underline">{{ $a['back'] }}</a>
    </form>
</x-site>
