@extends('errors.layout')
@section('code', '403')
@section('title', 'Accès refusé')
@section('message')
    <p>Vous n'avez pas le droit d'ouvrir cette page.</p>
    <p>Si ce lien vous a été envoyé, il a peut-être expiré : demandez à votre propriétaire de vous le renvoyer.</p>
@endsection
