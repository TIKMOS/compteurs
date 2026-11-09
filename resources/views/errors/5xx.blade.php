@extends('errors.layout')
@section('code', (string) $exception->getStatusCode())
@section('title', 'Service indisponible')
@section('message')
    <p>Le service rencontre un problème. Réessayez dans quelques instants.</p>
@endsection
