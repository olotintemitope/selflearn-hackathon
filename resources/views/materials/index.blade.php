<x-layouts.app title="Materials — SelfLearn">
    <div class="hero"><p class="eyebrow">Adaptive study from your own notes</p><h1>Turn a material into a grounded study map.</h1><p>Your original stays private. SelfLearn extracts source-linked concepts you can verify.</p></div>
    @foreach ($spaces as $space)
        <section class="card stack">
            <h2>{{ $space->title }}</h2>
            <form method="POST" action="{{ route('materials.store') }}" enctype="multipart/form-data" class="upload-grid">
                @csrf
                <input type="hidden" name="learning_space_id" value="{{ $space->id }}">
                <label>Material title <input name="title" value="{{ old('title') }}" required maxlength="150"></label>
                <label>Image or PDF <input type="file" name="material" accept="image/jpeg,image/png,image/webp,application/pdf" required></label>
                <button>Upload privately</button>
            </form>
            <div class="material-list">
                @forelse ($space->materials as $material)
                    <a class="material-row" href="{{ route('materials.show', $material) }}"><span>{{ $material->title }}</span><span class="status status-{{ $material->status }}">{{ ucfirst($material->status) }}</span></a>
                @empty
                    <p class="muted">No materials yet. Upload science notes, technical documentation, or any subject you want to learn.</p>
                @endforelse
            </div>
        </section>
    @endforeach
</x-layouts.app>
