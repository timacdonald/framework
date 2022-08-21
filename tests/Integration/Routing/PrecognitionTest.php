<?php

namespace Illuminate\Tests\Integration\Routing;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Middleware\Precognition;
use Illuminate\Foundation\Routing\PredictsOutcomes;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

function fail() {
    throw new Exception('The controller for this request was executed when it should not have been.');
}

class PrecognitionTest extends TestCase
{
    public function testItReturnsEmptyResponseViaClosureRoutes()
    {
        Route::get('test-route', fn () => fail())
            ->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');
        $response->assertHeader('Vary', 'Precognition');
    }

    public function testItReturnsEmptyResponseViaControllerRoutes()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'methodWithNoPrediction'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');
        $response->assertHeader('Vary', 'Precognition');
    }

    public function testItCanReturnResponseFromPrediction()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'methodWithPredictionReturningConflictResponse'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertStatus(409);
        $response->assertHeader('Precognition', 'true');
    }

    public function testItExposesPrecognitiveMethodOnRequest()
    {
        Route::get('test-route', fn () => fail())
            ->middleware(Precognition::class);

        $this->get('test-route');
        $this->assertNull(request()->attributes->get('precognitive'));
        $this->assertFalse(request()->precognitive());

        $this->get('test-route', ['Precognition' => 'true']);
        $this->assertTrue(request()->attributes->get('precognitive'));
        $this->assertTrue(request()->precognitive());
    }

    public function testItProvidesControllerMethodArgumentsToPredictionMethod()
    {
        Route::get('test-route/{user}', [PrecognitionTestController::class, 'methodWithPredictionReturningArguments'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route/1234567890', ['Precognition' => 'true']);

        $response->assertOk();
        $response->assertExactJson(['Illuminate\\Http\\Request', '1234567890']);
    }

    public function testItCanExcludeValidationRulesWhenPrecognitive()
    {
        Route::post('test-route', fn (PrecognitionTestRequest $request) => fail())
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            'required_integer' => 'foo',
            'required_integer_when_not_precognitive' => 'foo',
        ], [
            'Precognition' => 'true',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors', [
            'required_integer' => [
                'The required integer must be an integer.',
            ],
        ]);
    }

    public function testItRunsExcludedRulesWhenNotPrecognitive()
    {
        Route::post('test-route', fn (PrecognitionTestRequest $request) => fail())
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            'required_integer' => 'foo',
            'required_integer_when_not_precognitive' => 'foo',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors', [
            'required_integer' => [
                'The required integer must be an integer.',
            ],
            'required_integer_when_not_precognitive' => [
                'The required integer when not precognitive must be an integer.',
            ],
        ]);
    }

    public function testClientCanSpecifyInputToValidate()
    {
        Route::post('test-route', fn (PrecognitionTestRequest $request) => fail())
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            // 'required_integer' => 'foo',
            'optional_integer_1' => 'foo',
            'optional_integer_2' => 'foo',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'optional_integer_1,optional_integer_2',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors', [
            'optional_integer_1' => [
                'The optional integer 1 must be an integer.',
            ],
            'optional_integer_2' => [
                'The optional integer 2 must be an integer.',
            ],
        ]);
    }

    public function testClientCanSpecifyNoInputsToValidate()
    {
        Route::post('test-route', fn (PrecognitionTestRequest $request) => fail())
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            // 'required_integer' => 'foo',
            'required_integer_when_not_precognitive' => 'foo',
            'optional_integer_1' => 'foo',
            'optional_integer_2' => 'foo',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => '',
        ]);

        $response->assertNoContent();
    }

    public function testItAppliesHeadersWhenExceptionThrownInPrediction()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'methodWherePredictionThrowsModelNotFoundException'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertNotFound();
        $response->assertHeader('Precognition', 'true');
        $response->assertHeader('Vary', 'Precognition');
    }

    public function testItAppliesHeadersWhenFlowControlExceptionIsThrown()
    {
        // Check with Authorize middleware first...
        Gate::define('alwaysDeny', fn () => false);
        Route::get('test-route-before', fn () => fail())
            ->middleware(['can:alwaysDeny', Precognition::class]);

        $response = $this->get('test-route-before', ['Precognition' => 'true']);

        $response->assertForbidden();
        $response->assertHeader('Precognition', 'true');
        $response->assertHeader('Vary', 'Precognition');

        // Check with Authorize middleware last...
        Route::get('test-route-after', fn () => fail())
            ->middleware([Precognition::class, 'can:alwaysDeny']);

        $response = $this->get('test-route-after', ['Precognition' => 'true']);

        $response->assertForbidden();
        $response->assertHeader('Precognition', 'true');
        $response->assertHeader('Vary', 'Precognition');
    }

    public function testContollerCanResolvePredictionAndReceivePayload()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'methodWherePredictionPassesValuesToOutcome'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route');

        $response->assertOk();
        $response->assertExactJson([
            'first' => 'expected',
            'second' => 'values',
            'third' => 'passed',
        ]);
    }

    public function testWhenResponseIsReturnedFromPredictionDuringResolveItReturnsThatResponseToTheClient()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'methodWherePredictionReturnsResponseAndOutcomeResolvesPrediction'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route');

        $response->assertOk();
        $this->assertSame('prediction-response', $response->content());
        $response->assertHeaderMissing('Precognition');
    }

    public function testArbitraryPredictionResponseIsParsedResponse()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'methodWherePredictionReturnsArrayAndOutcomeResolvesPrediction'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route');
        $response->assertJson(['prediction' => 'response']);
        $response->assertHeaderMissing('Precognition');

        $response = $this->get('test-route', ['Precognition' => 'true']);
        $response->assertJson(['prediction' => 'response']);
        $response->assertHeader('Precognition', 'true');
        $response->assertHeader('Vary', 'Precognition');
    }

    public function testClientCanSpecifyInputsToValidateWhenUsingControllerValidate()
    {
        Route::post('test-route', [PrecognitionTestController::class, 'methodWherePredicitionValidatesViaControllerValidate'])
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            // 'required_integer' => 'foo',
            'required_integer_when_not_precognitive' => 'foo',
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'optional_integer_1,optional_integer_2',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors', [
            'optional_integer_1' => [
                'The optional integer 1 must be an integer.',
            ],
            'optional_integer_2' => [
                'The optional integer 2 must be an integer.',
            ],
        ]);
    }

    public function testClientCanSpecifyInputsToValidateWhenUsingControllerValidateWithBag()
    {
        Route::post('test-route', [PrecognitionTestController::class, 'methodWherePredicitionValidatesViaControllerValidateWithBag'])
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            // 'required_integer' => 'foo',
            'required_integer_when_not_precognitive' => 'foo',
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'optional_integer_1,optional_integer_2',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors', [
            'optional_integer_1' => [
                'The optional integer 1 must be an integer.',
            ],
            'optional_integer_2' => [
                'The optional integer 2 must be an integer.',
            ],
        ]);
    }

    public function testClientCanSpecifyInputsToValidateWhenUsingRequestValidate()
    {
        Route::post('test-route', [PrecognitionTestController::class, 'methodWherePredicitionValidatesViaRequestValidate'])
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            // 'required_integer' => 'foo',
            'required_integer_when_not_precognitive' => 'foo',
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'optional_integer_1,optional_integer_2',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors', [
            'optional_integer_1' => [
                'The optional integer 1 must be an integer.',
            ],
            'optional_integer_2' => [
                'The optional integer 2 must be an integer.',
            ],
        ]);
    }

    public function testClientCanSpecifyInputsToValidateWhenUsingRequestValidateWithBag()
    {
        Route::post('test-route', [PrecognitionTestController::class, 'methodWherePredicitionValidatesViaRequestValidateWithBag'])
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            // 'required_integer' => 'foo',
            'required_integer_when_not_precognitive' => 'foo',
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'optional_integer_1,optional_integer_2',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors', [
            'optional_integer_1' => [
                'The optional integer 1 must be an integer.',
            ],
            'optional_integer_2' => [
                'The optional integer 2 must be an integer.',
            ],
        ]);
    }

    public function testClientCanSpecifyInputsToValidateWhenUsingControllerValidateWithPassingArrayOfRules()
    {
        Route::post('test-route', [PrecognitionTestController::class, 'methodWherePredicitionValidatesViaControllerValidateWith'])
            ->middleware(Precognition::class);

        $response = $this->postJson('test-route', [
            // 'required_integer' => 'foo',
            'required_integer_when_not_precognitive' => 'foo',
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'optional_integer_1,optional_integer_2',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors', [
            'optional_integer_1' => [
                'The optional integer 1 must be an integer.',
            ],
            'optional_integer_2' => [
                'The optional integer 2 must be an integer.',
            ],
        ]);
    }

    public function testItAppendsAnAdditionalVaryHeaderInsteadOfReplacingAnyExistingVaryHeaders()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'methodWherePredictionSetsVaryHeaderOnReturnedResponse'])
            ->middleware([Precognition::class]);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertHeader('Vary', 'X-Inertia, Precognition');
    }

    public function testItThrowsExceptionWhenControllerMethodDoesntExist()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'undefinedMethod'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route', [
            'Precognition' => 'true',
        ]);

        $response->assertStatus(500);
        $this->assertSame('Attempting to predict the outcome of the [Illuminate\\Tests\\Integration\\Routing\\PrecognitionTestController::undefinedMethod()] method but it is not defined.', $response->exception->getMessage());
    }
}

class PrecognitionTestController
{
    use PredictsOutcomes, ValidatesRequests;

    public function methodWithNoPrediction()
    {
        fail();
    }

    public function methodWithPredictionReturningConflictResponsePrediction()
    {
        return response('', Response::HTTP_CONFLICT);
    }

    public function methodWithPredictionReturningConflictResponse()
    {
        fail();
    }

    public function methodWithPredictionReturningArgumentsPrediction($request, $user)
    {
        return response()->json([$request::class, $user]);
    }

    public function methodWithPredictionReturningArguments(Request $request, string $user)
    {
        fail();
    }

    public function methodWherePredictionSetsVaryHeaderOnReturnedResponse()
    {
        fail();
    }

    public function methodWherePredictionSetsVaryHeaderOnReturnedResponsePrediction()
    {
        return response('expected')->header('Vary', 'X-Inertia');
    }


    public function methodWherePredictionThrowsModelNotFoundExceptionPrediction()
    {
        throw new ModelNotFoundException();
    }

    public function methodWherePredictionThrowsModelNotFoundException()
    {
        fail();
    }

    public function methodWherePredictionPassesValuesToOutcomePrediction()
    {
        $this->passToOutcome('expected', 'values');
        $this->passToOutcome('passed');
    }

    public function methodWherePredictionPassesValuesToOutcome()
    {
        [$first, $second, $third] = $this->resolvePrediction();

        return [
            'first' => $first,
            'second' => $second,
            'third' => $third,
        ];
    }

    public function methodWherePredictionReturnsResponseAndOutcomeResolvesPredictionPrediction()
    {
        return response('prediction-response');
    }

    public function methodWherePredictionReturnsResponseAndOutcomeResolvesPrediction()
    {
        $this->resolvePrediction();

        fail();
    }

    public function methodWherePredictionReturnsArrayAndOutcomeResolvesPredictionPrediction()
    {
        return ['prediction' => 'response'];
    }

    public function methodWherePredictionReturnsArrayAndOutcomeResolvesPrediction()
    {
        $this->resolvePrediction();

        fail();
    }

    public function methodWherePredicitionValidatesViaControllerValidatePrediction($request)
    {
        $this->validate($request, [
            'required_integer' => 'required|integer',
            'required_integer_when_not_precognitive' => $this->whenNotPrecognitive('required|integer'),
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ]);
    }

    public function methodWherePredicitionValidatesViaControllerValidate(Request $request)
    {
        fail();
    }

    public function methodWherePredicitionValidatesViaControllerValidateWithBagPrediction($request)
    {
        $this->validateWithBag('custom-bag', $request, [
            'required_integer' => 'required|integer',
            'required_integer_when_not_precognitive' => $this->whenNotPrecognitive('required|integer'),
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ]);
    }

    public function methodWherePredicitionValidatesViaControllerValidateWithBag(Request $request)
    {
        fail();
    }

    public function methodWherePredicitionValidatesViaRequestValidatePrediction($request)
    {
        $request->validate([
            'required_integer' => 'required|integer',
            'required_integer_when_not_precognitive' => $this->whenNotPrecognitive('required|integer'),
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ]);
    }

    public function methodWherePredicitionValidatesViaRequestValidate(Request $request)
    {
        fail();
    }

    public function methodWherePredicitionValidatesViaRequestValidateWithBagPrediction($request)
    {
        $request->validateWithBag('custom-bag', [
            'required_integer' => 'required|integer',
            'required_integer_when_not_precognitive' => $this->whenNotPrecognitive('required|integer'),
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ]);
    }

    public function methodWherePredicitionValidatesViaRequestValidateWithBag(Request $request)
    {
        fail();
    }

    public function methodWherePredicitionValidatesViaControllerValidateWithPrediction($request)
    {
        $this->validateWith([
            'required_integer' => 'required|integer',
            'required_integer_when_not_precognitive' => $this->whenNotPrecognitive('required|integer'),
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ]);
    }

    public function methodWherePredicitionValidatesViaControllerValidateWith(Request $request)
    {
        fail();
    }
}

class PrecognitionTestRequest extends FormRequest
{
    public function rules()
    {
        return [
            'required_integer' => 'required|integer',
            'required_integer_when_not_precognitive' => $this->whenNotPrecognitive('required|integer'),
            'optional_integer_1' => 'integer',
            'optional_integer_2' => 'integer',
        ];
    }
}
