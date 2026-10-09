<?php

namespace App\Support;

/** Normalises Persian/Arabic text so "علي" == "علی", "۱۲" == "12", extra spaces and ZWNJ don't matter. */
class TextNormalizer
{
    public static function normalize(?string $s): string
    {
        $s = (string) $s;
        $s = strtr($s, [
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
            '‌' => ' ', '‍' => '', 'ـ' => '',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $s);   // harakat
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return mb_strtolower($s);
    }
}
