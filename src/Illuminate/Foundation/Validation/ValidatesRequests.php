<?php

namespace Illuminate\Foundation\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ValidatesRequests
{
    /**
     * Run the validation routine against the given validator.
     *
     * @param  \Illuminate\Contracts\Validation\Validator|array  $validator
     * @param  \Illuminate\Http\Request|null  $request
     * @return array
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateWith($validator, Request $request = null)
    {
        $request = $request ?: request();

        if (is_array($validator)) {
            $validator = $this->getValidationFactory()->make($request->all(), $this->parseValidationRules($validator, $request));
        } else {
            $validator = $validator->setRules($this->parseValidationRules($validator->getRules(), $request));
        }

        return $validator->after($this->precognitionAfterHook($request))->validate();
    }

    /**
     * Validate the given request with the given rules.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $rules
     * @param  array  $messages
     * @param  array  $customAttributes
     * @return array
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(Request $request, array $rules,
                             array $messages = [], array $customAttributes = [])
    {
        return $this->getValidationFactory()->make(
            $request->all(), $this->parseValidationRules($rules, $request),
            $messages, $customAttributes
        )->after($this->precognitionAfterHook($request))->validate();
    }

    /**
     * Validate the given request with the given rules.
     *
     * @param  string  $errorBag
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $rules
     * @param  array  $messages
     * @param  array  $customAttributes
     * @return array
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateWithBag($errorBag, Request $request, array $rules,
                                    array $messages = [], array $customAttributes = [])
    {
        try {
            return $this->validate($request, $rules, $messages, $customAttributes);
        } catch (ValidationException $e) {
            $e->errorBag = $errorBag;

            throw $e;
        }
    }

    /**
     * Get a validation factory instance.
     *
     * @return \Illuminate\Contracts\Validation\Factory
     */
    protected function getValidationFactory()
    {
        return app(Factory::class);
    }

    /**
     * The after validation Precognition hook.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Closure
     */
    protected function precognitionAfterHook($request)
    {
        return function ($validator) use ($request) {
            if (
                $validator->messages()->isEmpty()
                && $request->precognitive()
                && $request->allowsPrecognitionValidationRuleFiltering()
                && $request->headers->has('Precognition-Validate-Only')
            ) {
                throw new HttpResponseException(app('precognitive.emptyResponse'));
            }
        };
    }

    /**
     * Parse the validation rules to run.
     *
     * @param  array  $rules
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function parseValidationRules($rules, $request)
    {
        return $request->precognitive()
            ? app('precognitive.validationRuleFilter')($rules, $request)
            : $rules;
    }
}
