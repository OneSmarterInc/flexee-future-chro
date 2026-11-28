@php($a = app(\App\Ashby\Content\ContentPack::class)->file('common')['account'])
<x-site :title="$a['settings']" :sub="$a['settings']">
    <div class="max-w-xl">
        <h1 class="mb-4 text-3xl font-extrabold">{{ $a['settings'] }}</h1>
        @include('settings.tabs', ['here' => 'profile'])

        @if (session('saved'))
            <p class="notice">{{ $a['saved'] }}</p>
        @endif
        @if ($mustVerifyEmail && auth()->user()->email_verified_at === null)
            <p class="notice">{{ $a['email_changed'] }}</p>
        @endif

        <form method="POST" action="{{ route('profile.update') }}" class="grid gap-4">
            @csrf
            @method('PATCH')
            <label class="field">{{ $a['name'] }}
                <input name="name" value="{{ old('name', auth()->user()->name) }}" required autocomplete="name">
            </label>
            @error('name') <p class="error">{{ $message }}</p> @enderror
            <label class="field">{{ $a['email'] }}
                <input type="email" name="email" value="{{ old('email', auth()->user()->email) }}" required autocomplete="email">
            </label>
            @error('email') <p class="error">{{ $message }}</p> @enderror
            <div><button class="btn btn-main">{{ $a['save'] }}</button></div>
        </form>

        <h2 class="mt-10 mb-1 text-xl font-bold">{{ $a['delete_title'] }}</h2>
        <p class="soft mb-3">{{ $a['delete_lead'] }}</p>
        <form method="POST" action="{{ route('profile.destroy') }}" class="grid gap-4">
            @csrf
            @method('DELETE')
            <label class="field">{{ $a['password'] }}
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            @error('password', 'userDeletion') <p class="error">{{ $message }}</p> @enderror
            @error('password') <p class="error">{{ $message }}</p> @enderror
            <div><button class="btn btn-danger">{{ $a['delete'] }}</button></div>
        </form>
    </div>
</x-site>
