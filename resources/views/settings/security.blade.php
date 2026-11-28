@php($a = app(\App\Ashby\Content\ContentPack::class)->file('common')['account'])
<x-site :title="$a['settings']" :sub="$a['settings']">
    <div class="max-w-xl">
        <h1 class="mb-4 text-3xl font-extrabold">{{ $a['settings'] }}</h1>
        @include('settings.tabs', ['here' => 'security'])

        @if (session('saved'))
            <p class="notice">{{ $a['password_saved'] }}</p>
        @endif
        <form method="POST" action="{{ route('user-password.update') }}" class="grid gap-4">
            @csrf
            @method('PUT')
            <label class="field">{{ $a['current_password'] }}
                <input type="password" name="current_password" required autocomplete="current-password">
            </label>
            @error('current_password') <p class="error">{{ $message }}</p> @enderror
            <label class="field">{{ $a['new_password'] }}
                <input type="password" name="password" required autocomplete="new-password">
            </label>
            @error('password') <p class="error">{{ $message }}</p> @enderror
            <label class="field">{{ $a['again'] }}
                <input type="password" name="password_confirmation" required autocomplete="new-password">
            </label>
            <div><button class="btn btn-main">{{ $a['save_password'] }}</button></div>
        </form>

        @if ($canManageTwoFactor)
            <h2 class="mt-10 mb-2 text-xl font-bold">{{ $a['two_factor_title'] }}</h2>
            @if ($twoFactorState === 'off')
                <p class="soft mb-3">{{ $a['two_factor_off'] }}</p>
                <form method="POST" action="{{ route('two-factor.enable') }}">
                    @csrf
                    <button class="btn btn-main">{{ $a['turn_on'] }}</button>
                </form>
            @elseif ($twoFactorState === 'pending')
                <p class="mb-3">{{ $a['scan'] }}</p>
                <div class="mb-3 inline-block rounded-lg border border-[#e3e6ea] bg-white p-3">{!! $qrSvg !!}</div>
                <p class="soft text-sm">{{ $a['setup_key'] }} <code class="font-mono">{{ $setupKey }}</code></p>
                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-4 grid gap-4">
                    @csrf
                    <label class="field">{{ $a['code'] }}
                        <input name="code" inputmode="numeric" autocomplete="one-time-code" required>
                    </label>
                    @error('code', 'confirmTwoFactorAuthentication') <p class="error">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button class="btn btn-main">{{ $a['confirm'] }}</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-2">
                    @csrf
                    @method('DELETE')
                    <button class="btn">{{ $a['turn_off'] }}</button>
                </form>
            @else
                <p class="mb-3">{{ $a['two_factor_on'] }}</p>
                <div x-data="{ open: {{ $showCodes ? 'true' : 'false' }} }" class="card">
                    <h3 class="font-bold">{{ $a['recovery_title'] }}</h3>
                    <p class="soft mb-2 text-sm">{{ $a['recovery_note'] }}</p>
                    <button type="button" class="btn" x-show="!open" x-on:click="open = true">{{ $a['show_codes'] }}</button>
                    <ul x-show="open" x-cloak class="my-2 grid grid-cols-2 gap-1 font-mono text-sm">
                        @foreach ($recoveryCodes as $code)
                            <li>{{ $code }}</li>
                        @endforeach
                    </ul>
                    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" x-show="open" x-cloak>
                        @csrf
                        <button class="btn">{{ $a['new_codes'] }}</button>
                    </form>
                </div>
                <form method="POST" action="{{ route('two-factor.disable') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-danger">{{ $a['turn_off'] }}</button>
                </form>
            @endif
        @endif
    </div>
</x-site>
