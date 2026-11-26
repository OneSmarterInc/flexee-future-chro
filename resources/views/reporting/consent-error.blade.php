@extends('reporting.layout')
@section('title', 'Flexee reporting')
@section('body')
    <div class="card error" role="alert">
        <h1>Can't connect</h1>
        <p>{{ $message }}</p>
        <p><a href="{{ url('/') }}">Back to The Future CHRO</a></p>
    </div>
@endsection
