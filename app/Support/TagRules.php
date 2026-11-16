<?php

namespace App\Support;

final class TagRules
{
    public static function rules(): array
    {
        return ['tags' => ['sometimes', 'array', 'max:30'], 'tags.*' => ['required', 'string', 'max:80', 'distinct']];
    }
}
