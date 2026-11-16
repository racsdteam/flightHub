@extends('errors.layout')
@section('code', '419')
@section('title', 'Page expired')
@section('message', 'Your session ended (after 30 minutes without activity) or the page was open too long. Sign in again to continue.')
@section('actions')<a class="button" href="{{ url('/login') }}">Sign in</a>@endsection
