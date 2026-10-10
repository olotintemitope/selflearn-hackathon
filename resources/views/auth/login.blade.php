<x-layouts.app title="Sign in — SelfLearn">
    <section class="auth-grid">
        <form method="POST" action="{{ route('login.store') }}" class="card stack">
            @csrf
            <h1>Welcome back</h1>
            <label>Email <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
            <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
            <button>Sign in</button>
        </form>
        <form method="POST" action="{{ route('register') }}" class="card stack">
            @csrf
            <h2>Create your study space</h2>
            <label>Name <input name="name" required autocomplete="name"></label>
            <label>Email <input type="email" name="email" required autocomplete="email"></label>
            <label>Password <input type="password" name="password" required autocomplete="new-password"></label>
            <label>Confirm password <input type="password" name="password_confirmation" required autocomplete="new-password"></label>
            <button>Create account</button>
        </form>
    </section>
</x-layouts.app>
