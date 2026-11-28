@php($a = app(\App\Ashby\Content\ContentPack::class)->file('common')['account'])
<x-site :title="$a['code_title']" narrow>
    <div x-data="{ recovery: {{ $errors->has('recovery_code') ? 'true' : 'false' }} }">
        <h1 class="mb-1 text-3xl font-extrabold">{{ $a['code_title'] }}</h1>
        <p class="soft mb-5" x-show="!recovery">{{ $a['code_lead'] }}</p>
        <p class="soft mb-5" x-show="recovery" x-cloak>{{ $a['recovery_lead'] }}</p>
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="grid gap-4">
            @csrf
            <label class="field" x-show="!recovery">{{ $a['code'] }}
                <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus x-bind:disabled="recovery">
            </label>
            <label class="field" x-show="recovery" x-cloak>{{ $a['recovery_code'] }}
                <input name="recovery_code" autocomplete="off" x-bind:disabled="!recovery">
            </label>
            @error('code') <p class="error">{{ $message }}</p> @enderror
            @error('recovery_code') <p class="error">{{ $message }}</p> @enderror
            <button class="btn btn-main">{{ $a['go_on'] }}</button>
            <button type="button" class="soft cursor-pointer text-left text-sm underline" x-on:click="recovery = !recovery">
                <span x-show="!recovery">{{ $a['use_recovery'] }}</span>
                <span x-show="recovery" x-cloak>{{ $a['use_code'] }}</span>
            </button>
        </form>
    </div>
</x-site>
