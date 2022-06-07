<?php

namespace Illuminate\Translation;

use Illuminate\Support\Str;
use Illuminate\Support\Stringable as SupportStringable;
use Stringable;

class PotentiallyTranslatedString implements Stringable
{
    /**
     * The string that may be traslated.
     *
     * @var string
     */
    protected $string;

    /**
     * The translated string.
     *
     * @var string
     */
    protected $translation;

    /**
     * The validator that may perform the translation.
     *
     * @var \Illuminate\Contracts\Translation\Translator
     */
    protected $translator;

    /**
     * Create a new Potentially Translated String.
     *
     * @param  string  $string
     * @param \Illuminate\Contracts\Translation\Translator  $translator
     */
    public function __construct($string, $translator)
    {
        $this->string = $string;

        $this->translator = $translator;
    }

    /**
     * Translate the string.
     *
     * @return $this
     */
    public function translate()
    {
        $this->translation = $this->translator->get($this->string);

        return $this;
    }

    /**
     * Get the original string.
     *
     * @return string
     */
    public function original()
    {
        return $this->string;
    }

    /**
     * Get the potentially translated string.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->translation ?? $this->string;
    }

    /**
     * Get the potentially translated string.
     *
     * @return string
     */
    public function toString()
    {
        return (string) $this;
    }
}
