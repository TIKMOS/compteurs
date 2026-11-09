@extends('errors.layout')
@section('code', '500')
@section('title', 'Une erreur est survenue')
@section('message')
    <p>Le service a rencontré un problème inattendu. L'incident est enregistré.</p>
    <p>Vos saisies déjà enregistrées ne sont pas perdues. Réessayez dans quelques instants.</p>
@endsection
