<x-layouts.app title="Create account: SelfLearn">
    <section class="auth-shell">
        <form method="POST" action="{{ route('register.store') }}" class="card stack">
            @csrf
            <p class="eyebrow">Start learning</p>
            <h1>Create your study space</h1>
            <p class="lede">Bring your notes. SelfLearn will turn them into source-linked concepts.</p>
            <label>Name <input name="name" value="{{ old('name') }}" required autocomplete="name"></label>
            <label>Email <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
            <label>Password <input type="password" name="password" required autocomplete="new-password"></label>
            <label>Confirm password <input type="password" name="password_confirmation" required autocomplete="new-password"></label>
            <button>Create account</button>
            <p class="muted">Already have an account? <a href="{{ route('login') }}">Sign in</a>.</p>
        </form>
    </section>
</x-layouts.app>
