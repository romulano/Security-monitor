{{-- Matéria tirada do feed público (fora de segurança), sem apagar: o 410 diz ao buscador que ela saiu de propósito. --}}
@extends('errors::minimal')

@section('title', 'Fora do feed')
@section('code', '410')
@section('message', 'Esta matéria saiu do feed do Security Monitor')
