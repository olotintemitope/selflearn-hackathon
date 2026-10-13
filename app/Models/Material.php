<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Material extends Model
{
    public const ROLES = [
        'study_notes' => 'Study notes',
        'syllabus' => 'Syllabus or course outline',
        'textbook' => 'Textbook or reading',
        'past_paper' => 'Past exam paper',
        'marking_scheme' => 'Marking scheme or answer key',
        'teacher_handout' => 'Teacher handout',
        'reference' => 'Other reference material',
        'other' => 'Other',
    ];

    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $fillable = [
        'title', 'role', 'private_source_path', 'original_filename', 'mime_type',
        'size_bytes', 'status', 'extraction_version', 'metadata', 'failure_message',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? self::ROLES['other'];
    }

    public function learningSpace(): BelongsTo
    {
        return $this->belongsTo(LearningSpace::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Material $material): void {
            $material->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(MaterialChunk::class);
    }

    public function pastPaperQuestions(): HasMany
    {
        return $this->hasMany(PastPaperQuestion::class);
    }

    public function confirmedMarkingSchemes(): HasMany
    {
        return $this->hasMany(PastPaperMarkingSchemeMatch::class, 'paper_material_id');
    }
}
