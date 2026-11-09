@extends('errors.layout')
@section('code', (string) $exception->getStatusCode())
@section('title', 'Demande impossible')
@section('message')
    <p>Cette demande n'a pas pu aboutir. Revenez en arrière et réessayez.</p>
@endsection
