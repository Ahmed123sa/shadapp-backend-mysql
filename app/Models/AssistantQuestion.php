<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the client assistant (CLIENT_ASSISTANT_PLAN.md ٥.١): a fixed data
 * question, a FAQ the super admin wrote, or the single "settings" row.
 */
class AssistantQuestion extends Model
{
    public const KIND_DATA = 'data';
    public const KIND_FAQ = 'faq';
    public const KIND_SETTINGS = 'settings';

    protected $fillable = [
        'key', 'kind', 'question', 'answers', 'action_target', 'is_enabled', 'sort_order', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'question' => 'array',
            'answers' => 'array',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The default wording for this row's state, from config/assistant.php. */
    public function defaultAnswer(string $state): ?array
    {
        $s = config("assistant.questions.{$this->key}.states.{$state}");

        return $s ? ['ar' => $s['ar'], 'en' => $s['en']] : null;
    }
}
