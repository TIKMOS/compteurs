@extends('errors.layout')
@section('code', '404')
@section('title', 'Page introuvable')
@section('message')
    <p>Cette page n'existe pas, ou elle n'est pas accessible avec votre compte.</p>
    <p>Si vous avez suivi un lien reçu par message, demandez à votre propriétaire de vous le renvoyer.</p>
@endsection
