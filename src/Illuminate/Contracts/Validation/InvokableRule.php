<?php

namespace Illuminate\Contracts\Validation;

interface InvokableRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Closure(string): TranslatableString  $fail
     * @return void
     */
    public function __invoke($attribute, $value, $fail);
}
