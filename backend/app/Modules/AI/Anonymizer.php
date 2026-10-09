<?php

namespace App\Modules\AI;

use App\Support\TextNormalizer;

/** Data minimisation: nothing identifying leaves the platform in prompts. */
class Anonymizer
{
    /** @param list<string> $names known personal names (student/teacher/parents) to mask */
    public static function scrub(string $text, array $names = []): string
    {
        $t = strtr($text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $t = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[ایمیل]', $t);
        $t = preg_replace('/(?<!\d)(\+?98|0)?9\d{9}(?!\d)/', '[تلفن]', $t);
        $t = preg_replace('/(?<!\d)\d{10}(?!\d)/', '[کد ملی]', $t);
        $t = preg_replace('#https?://\S+#i', '[پیوند]', $t);
        foreach (array_filter($names, fn ($n) => mb_strlen(trim($n)) >= 2) as $n) {
            $t = preg_replace('/'.preg_quote(trim($n), '/').'/u', '[نام]', $t);
        }

        return $t;
    }
}
