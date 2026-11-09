@extends('errors.layout')
@section('code', '503')
@section('title', 'Maintenance en cours')
@section('message')
    <p>Le service est en cours de mise à jour. Il sera de nouveau disponible dans quelques minutes.</p>
@endsection
@section('actions')
    <a class="button primary" href="javascript:location.reload()">Réessayer</a>
@endsection
