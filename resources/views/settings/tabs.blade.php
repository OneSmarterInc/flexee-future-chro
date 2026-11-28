<nav class="mb-6 flex flex-wrap gap-2" aria-label="{{ $a['settings'] }}">
    <a href="{{ route('profile.edit') }}" @class(['btn', 'btn-main' => $here === 'profile'])>{{ $a['profile_tab'] }}</a>
    <a href="{{ route('security.edit') }}" @class(['btn', 'btn-main' => $here === 'security'])>{{ $a['security_tab'] }}</a>
</nav>
