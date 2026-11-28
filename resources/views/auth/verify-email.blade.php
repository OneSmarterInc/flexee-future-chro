@php($a = app(\App\Ashby\Content\ContentPack::class)->file('common')['account'])
<x-site :title="$a['verify_title']" narrow>
    <h1 class="mb-1 text-3xl font-extrabold">{{ $a['verify_title'] }}</h1>
    <p class="soft mb-5">{{ $a['verify_lead'] }}</p>
    @if (session('status') === 'verification-link-sent')
        <p class="notice">{{ $a['sent'] }}</p>
    @endif
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button class="btn">{{ $a['resend'] }}</button>
    </form>
</x-site>
