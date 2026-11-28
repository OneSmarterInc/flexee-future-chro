@php($a = app(\App\Ashby\Content\ContentPack::class)->file('common')['account'])
<x-site :title="$a['reset_title']" narrow>
    <h1 class="mb-5 text-3xl font-extrabold">{{ $a['reset_title'] }}</h1>
    <form method="POST" action="{{ route('password.update') }}" class="grid gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <label class="field">{{ $a['email'] }}
            <input type="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="email">
        </label>
        @error('email') <p class="error">{{ $message }}</p> @enderror
        <label class="field">{{ $a['new_password'] }}
            <input type="password" name="password" required autocomplete="new-password">
        </label>
        @error('password') <p class="error">{{ $message }}</p> @enderror
        <label class="field">{{ $a['again'] }}
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>
        <button class="btn btn-main">{{ $a['save_password'] }}</button>
    </form>
</x-site>
