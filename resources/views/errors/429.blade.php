@extends('errors.layout')
@section('code', '429')
@section('title', 'Trop de tentatives')
@section('message')
    <p>Par sécurité, les essais sont limités. Patientez une minute avant de réessayer.</p>
    <p>Mot de passe oublié ? Utilisez le lien « Mot de passe oublié » de la page de connexion.</p>
@endsection
@section('actions')
    <a class="button primary" href="{{ url('/login') }}">Retour à la connexion</a>
@endsection
