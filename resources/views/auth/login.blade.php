<x-layouts.app title="Sign in — SelfLearn">
    <section class="auth-shell">
        <form method="POST" action="{{ route('login.store') }}" class="card stack">
            @csrf
            <p class="eyebrow">Your learning space</p>
            <h1>Welcome back</h1>
            <p class="lede">Sign in to continue studying from your own materials.</p>
            <label>Email <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
            <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
            <button>Sign in</button>
            <p class="muted">New to SelfLearn? <a href="{{ route('register') }}">Create an account</a>.</p>
        </form>
    </section>
</x-layouts.app>
