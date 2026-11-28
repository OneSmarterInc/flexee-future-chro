@php($a = app(\App\Ashby\Content\ContentPack::class)->file('common')['account'])
<x-site :title="$a['confirm_title']" narrow>
    <h1 class="mb-1 text-3xl font-extrabold">{{ $a['confirm_title'] }}</h1>
    <p class="soft mb-5">{{ $a['confirm_lead'] }}</p>
    <form method="POST" action="{{ route('password.confirm.store') }}" class="grid gap-4">
        @csrf
        <label class="field">{{ $a['password'] }}
            <input type="password" name="password" required autofocus autocomplete="current-password">
        </label>
        @error('password') <p class="error">{{ $message }}</p> @enderror
        <button class="btn btn-main">{{ $a['confirm'] }}</button>
    </form>
</x-site>
