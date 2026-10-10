<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function chunks(): HasMany
    {
        return $this->hasMany(MaterialChunk::class);
    }
}
