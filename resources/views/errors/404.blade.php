@extends('errors.layout')
@section('code', '404')
@section('title', 'Not found')
@section('message', 'This page or record does not exist. Check the address, or search for the flight from the Flights screen.')
@section('actions')<a class="button" href="{{ url('/') }}">Go to the dashboard</a>@endsection
