@extends('errors.layout')
@section('code', '419')
@section('title', 'Page expirée')
@section('message')
    <p>La page est restée ouverte trop longtemps et votre session a expiré. Rien n'a été enregistré.</p>
    <p>Rechargez la page, puis recommencez votre saisie.</p>
@endsection
@section('actions')
    <a class="button primary" href="javascript:location.reload()">Recharger la page</a>
    <a class="button secondary" href="{{ url('/login') }}">Se connecter</a>
@endsection
