<?php

namespace App\Support;

/**
 * Rules for the wording the super admin edits (CLIENT_ASSISTANT_PLAN.md ٤.٢):
 * every {placeholder} must be one the answer may use, and the ones the answer
 * is useless without must stay. Also the sample values behind the preview.
 */
class AssistantWording
{
    /** @return list<string> placeholders found in the text */
    public static function found(string $text): array
    {
        preg_match_all('/\{([^{}]*)\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * An error message in Arabic, or null when the text is fine.
     *
     * @param list<string> $allowed
     * @param list<string> $required
     */
    public static function problem(string $text, array $allowed, array $required): ?string
    {
        $found = self::found($text);

        foreach ($found as $name) {
            if (! in_array($name, $allowed, true)) {
                $list = $allowed ? '{' . implode('} {', $allowed) . '}' : 'مفيش خانات متاحة هنا';

                return "الخانة {{$name}} مش معروفة. المتاح: {$list}";
            }
        }

        foreach ($required as $name) {
            if (! in_array($name, $found, true)) {
                return "الرد لازم يحتوي على {{$name}}.";
            }
        }

        if (preg_match('/[{}]/', preg_replace('/\{[a-z_]+\}/', '', $text))) {
            return 'فيه قوس { أو } ناقص أو زيادة.';
        }

        return null;
    }

    /** Sample values for the preview and the "reset" example. */
    public static function sample(string $lang): array
    {
        $ar = $lang === 'ar';

        return [
            'name' => $ar ? 'أحمد' : 'Ahmed',
            'count' => '2',
            'remaining' => '12,500.00',
            'paid' => '37,500.00',
            'total' => '50,000.00',
            'currency' => 'SAR',
            'amount' => '5,000.00',
            'due_date' => $ar ? '15 نوفمبر 2026' : '15 Nov 2026',
            'title' => $ar ? 'عقد خدمات 2026' : 'Services contract 2026',
            'status' => $ar ? 'ساري' : 'active',
            'date' => $ar ? '5 نوفمبر 2026' : '5 Nov 2026',
            'time' => $ar ? '10:00 ص' : '10:00 AM',
            'manager' => $ar ? 'عمر خالد' : 'Omar Khaled',
        ];
    }
}
