@extends('errors.layout')
@section('code', '403')
@section('title', 'Not allowed')
@section('message', 'Your role does not allow this page or action. Ask an administrator if you need it.')
@section('actions')<a class="button" href="{{ url('/') }}">Go to the dashboard</a>@endsection
