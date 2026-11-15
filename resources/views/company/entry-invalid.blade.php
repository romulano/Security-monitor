@extends('layouts.app')

@section('title', 'Link sem validade · Security Monitor')

@section('content')
    <div class="mx-auto max-w-sm py-12">
        <h1 class="mb-1 text-lg font-semibold text-white">Este link não vale mais</h1>
        <p class="mb-6 text-sm text-ink-400">{{ $message }}</p>
        <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Ir para o login</a>
    </div>
@endsection
