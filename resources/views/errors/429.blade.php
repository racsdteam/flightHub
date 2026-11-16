@extends('errors.layout')
@section('code', '429')
@section('title', 'Too many requests')
@section('message', 'Too many requests came from your network in a short time. Wait a minute, then try again.')
@section('actions')<a class="button button-secondary" href="{{ url('/') }}">Back to the dashboard</a>@endsection
