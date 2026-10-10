<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'SelfLearn' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<header class="site-header">
    <a href="{{ route('materials.index') }}" class="brand">SelfLearn</a>
    @auth
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="link-button">Sign out</button></form>
    @endauth
</header>
<main class="container">
    @if (session('status')) <p class="notice" role="status">{{ session('status') }}</p> @endif
    @if ($errors->any())
        <div class="error" role="alert"><strong>Please fix the following:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    {{ $slot }}
</main>
</body>
</html>
