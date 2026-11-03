<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CLIENT_ASSISTANT_PLAN.md ٥.١ — the questions and answer wording of the
 * client assistant, editable by the super admin. A new table only: nothing
 * existing is touched. The defaults are inserted here (from config/assistant.php)
 * so the page has something to edit from day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_questions', function (Blueprint $table) {
            $table->id();
            // Fixed data questions carry a key (balance...); FAQ rows have none.
            $table->string('key')->nullable()->unique();
            // data | faq | settings (one row holding the assistant's name and welcome).
            $table->string('kind', 16)->index();
            $table->json('question')->nullable();
            // { "<state>": { "ar": "...", "en": "..." } }
            $table->json('answers')->nullable();
            $table->string('action_target', 32)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $rows = [];

        foreach (config('assistant.questions', []) as $key => $def) {
            $answers = [];
            foreach ($def['states'] as $state => $s) {
                $answers[$state] = ['ar' => $s['ar'], 'en' => $s['en']];
            }
            $rows[] = [
                'key' => $key,
                'kind' => 'data',
                'question' => json_encode($def['question'], JSON_UNESCAPED_UNICODE),
                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                'action_target' => null,
                'is_enabled' => true,
                'sort_order' => $def['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $settings = config('assistant.settings');
        $rows[] = [
            'key' => 'settings',
            'kind' => 'settings',
            'question' => json_encode($settings['name'], JSON_UNESCAPED_UNICODE),
            'answers' => json_encode(['welcome' => $settings['welcome']], JSON_UNESCAPED_UNICODE),
            'action_target' => null,
            'is_enabled' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('assistant_questions')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_questions');
    }
};
