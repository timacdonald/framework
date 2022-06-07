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
     * The prefix for the translation key.
     *
     * @var null|string
     */
    protected $prefix;

    /**
     * Create a new Potentially Translated String.
     *
     * @param  string  $string
     * @param \Illuminate\Contracts\Translation\Translator  $translator
     * @param  null|string  $prefix
     */
    public function __construct($string, $translator, $prefix = null)
    {
        $this->string = $string;

        $this->translator = $translator;

        $this->prefix = $prefix;
    }

    /**
     * Translate the string.
     *
     * @return $this
     */
    public function translate()
    {
        $this->translation = $this->translator->get($this->key());

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

    /**
     * The translation key.
     *
     * @return string
     */
    protected function key()
    {
        return ($this->prefix === null ? '' : Str::finish($this->prefix, '.')).$this->string;
    }
}
