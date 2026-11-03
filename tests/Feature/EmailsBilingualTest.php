<?php

namespace Tests\Feature;

use Tests\TestCase;

class EmailsBilingualTest extends TestCase
{
    public function test_every_email_has_an_arabic_block_and_an_english_block(): void
    {
        $dir = resource_path('views/emails');

        foreach (glob($dir.'/*.blade.php') as $file) {
            // The support form mail goes to the company's own support inbox.
            if (basename($file) === 'support-request.blade.php') {
                continue;
            }

            $html = file_get_contents($file);
            $name = basename($file);

            $this->assertStringContainsString('dir="rtl"', $html, "$name has no Arabic block");
            $this->assertStringContainsString('dir="ltr"', $html, "$name has no English block");
            $this->assertLessThan(strpos($html, 'dir="ltr"'), strpos($html, 'dir="rtl"'), "$name must start with Arabic");
        }
    }

    public function test_every_email_subject_carries_both_languages(): void
    {
        $files = array_merge(
            glob(app_path('Mail/*.php')),
            [app_path('Notifications/ResetPasswordNotification.php')],
        );

        foreach ($files as $file) {
            if (basename($file) === 'SupportRequestMail.php') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                "/subject[^\n]*[\x{0600}-\x{06FF}][^\n]* \| [^\n]*[A-Za-z]/u",
                file_get_contents($file),
                basename($file).' subject is not bilingual'
            );
        }
    }
}
