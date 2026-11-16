@extends('errors.layout')
@section('code', '500')
@section('title', 'Something went wrong')
@section('message', 'The hub could not complete this request. The error was logged; try again in a moment.')
@section('actions')<a class="button" href="{{ url('/') }}">Go to the dashboard</a>@endsection
