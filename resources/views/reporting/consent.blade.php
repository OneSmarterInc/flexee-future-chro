@extends('reporting.layout')
@section('title', 'Connect Flexee reporting')
@section('body')
    <div class="card">
        <h1>Connect Flexee reporting</h1>
        <p class="muted">{{ $client->label() }} is asking to read this site's reporting views.</p>
        <p><strong>It will be able to read:</strong></p>
        <ul>
            <li>classes, decision years and closes, as counts, states, dates and timings;</li>
            <li>AI use and its estimated cost, and the site's health.</li>
        </ul>
        <p>It reads only. It sees no person's name or email, no team's memo or question, and no team's results. Rehearsal classes are left out.</p>
        <dl>
            <dt class="muted">Signed in as</dt>
            <dd>{{ $user->name }} (administrator)</dd>
            <dt class="muted">Returns to</dt>
            <dd data-test="return-host"><strong>{{ $returnHost }}</strong></dd>
            <dt class="muted">Connector</dt>
            <dd>{{ $resource }}</dd>
        </dl>
        <form method="POST" action="{{ route('reporting.oauth.decide') }}">
            @csrf
            @foreach ($params as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
            @endforeach
            <button class="primary" type="submit" name="decision" value="approve" data-test="approve">Allow read-only access</button>
            <button class="ghost" type="submit" name="decision" value="deny" data-test="deny">Cancel</button>
        </form>
        <p class="muted">You can end this connection at any time on the Classes and logins page.</p>
    </div>
@endsection
