<?php

namespace Illuminate\Validation;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class InvokableValidationRule implements RuleContract, DataAwareRule, ValidatorAwareRule
{
    /**
     * The invokable that validates the attribute.
     *
     * @var \Illuminate\Contracts\Validation\InvokableRule
     */
    protected $invokable;

    /**
     * Indicates if the validation invokable failed.
     *
     * @var bool
     */
    protected $failed = false;

    /**
     * The validation error messages.
     *
     * @var array
     */
    protected $messages = [];

    /**
     * The current validator.
     *
     * @var \Illuminate\Validation\Validator
     */
    protected $validator;

    /**
     * The data under validation.
     *
     * @var array
     */
    protected $data = [];

    /**
     * Create a new Invokable validation rule.
     *
     * @param  \Illuminate\Contracts\Validation\InvokableRule  $invokable
     * @return void
     */
    public function __construct($invokable)
    {
        $this->invokable = $invokable;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $this->failed = false;

        if ($this->invokable instanceof DataAwareRule) {
            $this->invokable->setData($this->data);
        }

        if ($this->invokable instanceof ValidatorAwareRule) {
            $this->invokable->setValidator($this->validator);
        }

        $this->invokable->__invoke($attribute, $value, function ($message) {
            $this->failed = true;

            return $this->pendingPotentiallyTranslatedString($message);
        });

        return ! $this->failed;
    }

    /**
     * Get the validation error messages.
     *
     * @return array
     */
    public function message()
    {
        return $this->messages;
    }

    /**
     * Set the data under validation.
     *
     * @param  array  $data
     * @return $this
     */
    public function setData($data)
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Set the current validator.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return $this
     */
    public function setValidator($validator)
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Create a pending potentially translated string.
     *
     * @param  string  $message
     * @return \Illuminate\Translation\PotentiallyTranslatedString
     */
    protected function pendingPotentiallyTranslatedString($message)
    {
        return new class (
            $message,
            $this->validator->getTranslator(),
            fn ($message) => $this->messages[] = $message
        ) extends PotentiallyTranslatedString {
            protected $destructor;

            public function __construct($message, $translator, $destructor)
            {
                parent::__construct($message, $translator);

                $this->destructor = $destructor;
            }

            public function __destruct()
            {
                ($this->destructor)($this->toString());
            }
        };
    }
}
