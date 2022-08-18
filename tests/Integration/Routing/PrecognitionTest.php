<?php

namespace Illuminate\Tests\Integration\Routing;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Middleware\Precognition;
use Illuminate\Foundation\Routing\PredictsOutcomes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class PrecognitionTest extends TestCase
{
    public function testItProvidesSensibleEmptyResponseViaClosureRoutes()
    {
        Route::get('test-route', function () {
            throw new \Exception('xxxx');
        })->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');
        $response->assertHeader('Vary', 'Precognition');
    }

    public function testItProvidesSensibleEmptyResponseViaControllerRoutes()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'show'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');
    }

    public function testItCanImplementMagicMethodOnControllerToProvideResponse()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'update'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertStatus(409);
        $this->assertSame('Conflict', $response->content());
        $response->assertHeader('Precognition', 'true');
    }

    public function testItBindsPrecognitiveStateToContainer()
    {
        Route::get('test-route', function () {
            throw new \Exception('xxxx');
        })->middleware(Precognition::class);

        $this->assertFalse($this->app['precognitive']);

        $this->get('test-route', ['Precognition' => 'true']);

        $this->assertTrue($this->app['precognitive']);
    }

    public function testItBindsRequestMacro()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'checkPrecogMacro'])
            ->middleware(Precognition::class);

        $responses = [];
        $responses[] = $this->get('test-route')->content();
        $responses[] = $this->get('test-route', ['Precognition' => 'true'])->content();

        $this->assertSame(['no', 'yes'], $responses);
    }

    public function testItProvidesControllerMethodArgumentsToPredictionMethod()
    {
        Route::get('test-route/{user}', [PrecognitionTestController::class, 'checkArguments'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route/456', ['Precognition' => 'true']);

        $response->assertExactJson([
            'request' => 'Illuminate\\Http\\Request',
            'user' => '456',
            'count' => 2,
        ]);
    }

    public function testItCanSpecifyRulesThatShouldNotBeRunWhenPrecognitive()
    {
        Route::post('test-route', function (PrecognitionTestRequest $request) {
            //
        })->middleware([Precognition::class]);

        $response = $this->postJson('test-route', [
            'always' => 'foo',
            'whenNotPrecognitive' => 'foo',
        ], ['Precognition' => 'true']);

        $response->assertJsonPath('errors', [
            'always' => [
                'The always must be an integer.'
            ]
        ]);
    }

    public function testItCanExcludedRulesRunAsExpectedWithoutPrecognition()
    {
        Route::post('test-route', function (PrecognitionTestRequest $request) {
            //
        })->middleware([Precognition::class]);

        $response = $this->postJson('test-route', [
            'always' => 'foo',
            'whenNotPrecognitive' => 'foo',
        ]);

        $response->assertJsonPath('errors', [
            'always' => [
                'The always must be an integer.'
            ],
            'whenNotPrecognitive' => [
                'The when not precognitive must be an integer.'
            ]
        ]);
    }

    public function testClientCanSpecifyInputsToValidate()
    {
        Route::post('test-route', function (PrecognitionTestRequest $request) {
            //
        })->middleware([Precognition::class]);

        $response = $this->postJson('test-route', [
            'always' => 'foo',
            'sometimes_1' => 'foo',
            'sometimes_2' => 'foo',
            'whenNotPrecognitive' => 'foo',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'sometimes_1,sometimes_2',
        ]);


        $response->assertJsonPath('errors', [
            'sometimes_1' => [
                'The sometimes 1 must be an integer.'
            ],
            'sometimes_2' => [
                'The sometimes 2 must be an integer.'
            ]
        ]);
    }

    public function testItCanSpecifyNoRulesToValidate()
    {
        Route::post('test-route', function (PrecognitionTestRequest $request) {
            //
        })->middleware([Precognition::class]);

        $response = $this->postJson('test-route', [
            'always' => 'foo',
            'sometimes_1' => 'foo',
            'sometimes_2' => 'foo',
            'whenNotPrecognitive' => 'foo',
        ], [
            'Precognition' => 'true',
            'Precognition-Validate-Only' => '',
        ]);

        $response->assertNoContent();
    }

    public function testResponsesGeneratedViaExceptionBasedFlowControlHavePreparedHeaders()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'throwNotFound'])
            ->middleware([Precognition::class]);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertNotFound();
        $response->assertHeader('Precognition', 'true');

        // Authorize first...
        Gate::define('alwaysDeny', fn () => false);
        Route::get('test-route', function () {
            throw new Exception('xxxx');
        })->middleware([
            'can:alwaysDeny',
            Precognition::class,
        ]);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertForbidden();
        $response->assertHeader('Precognition', 'true');

        // Authorize last...
        Gate::define('alwaysDeny', fn () => false);
        Route::get('test-route', function () {
            throw new Exception('xxxx');
        })->middleware([
            Precognition::class,
            'can:alwaysDeny',
        ]);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertForbidden();
        $response->assertHeader('Precognition', 'true');
    }

    public function testPredictionMethodCanPassValuesToMainControllerMethod()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'passValuesBetweenMethods'])
            ->middleware([Precognition::class]);

        $response = $this->get('test-route');

        $response->assertOk();
        $response->assertExactJson([
            'Laravel' => 'PHP',
            'Vue' => 'JavaScript',
        ]);
    }

    public function testPredictionMethodReceivesArgumentsWhenResolvingPrediction()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'passValuesBetweenMethodsWithArguments'])
            ->middleware([Precognition::class]);

        $response = $this->get('test-route?q=41');

        $response->assertOk();
        $this->assertSame('41', $response->content());
    }

    public function testItCanReturnPassedOnValuesFromPrediction()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'returnPassOn'])
            ->middleware([Precognition::class]);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertNoContent();
    }

    public function testPrecognitionHeaderCanBeTrueOrOne()
    {
        Route::get('test-route', function () {
            throw new Exception('xxxx');
        })->middleware([Precognition::class]);

        $response = $this->get('test-route', ['Precognition' => '1']);
        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');

        $response = $this->get('test-route', ['Precognition' => 'true']);
        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');
    }

    public function testItCanCallPassToControllerMethodMultilpleTimesInPrediction()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'testCanPassToOutcomeMultilpleTimes'])
            ->middleware([Precognition::class]);

        $response = $this->get('test-route');

        $response->assertJson(['a', 'b', 'c']);
        $response->assertHeaderMissing('Precognition');
    }

    public function testWhenResponseIsReturnedFromPredictionDuringResolveItReturnsThatResponseToTheClient()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'testResponseFromPredictionIsReturned'])
            ->middleware([Precognition::class]);

        $response = $this->get('test-route');

        $this->assertSame('expected-response', $response->content());
        $response->assertHeaderMissing('Precognition');
    }

    public function testPredictionResponseIsParsedToSymfonyResponse()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'testResponseFromPredictionIsParsedToSymfonyResponse'])
            ->middleware([Precognition::class]);

        $response = $this->get('test-route');

        $response->assertJson(['expected' => 'response']);
        $response->assertHeaderMissing('Precognition');
    }

    public function testItAppendsAnAdditionalVaryHeaderInsteadOfReplacingAnyExistingHeaders()
    {
        $this->markTestIncomplete('WIP');
    }
}

class PrecognitionTestController
{
    use PredictsOutcomes;

    public function updatePrediction()
    {
        return response('Conflict', Response::HTTP_CONFLICT);
    }

    public function update()
    {
        throw new \Exception('xxxx');
    }

    public function show()
    {
        throw new \Exception('xxxx');
    }

    public function checkPrecogMacroPrediction()
    {
        return response(request()->precognitive() ? 'yes' : 'no');
    }

    public function checkPrecogMacro()
    {
        return request()->precognitive() ? 'yes' : 'no';
    }

    public function checkArguments(Request $request, string $user)
    {
        throw new \Exception('xxxx');
    }

    public function checkArgumentsPrediction($request, $user)
    {
        return response()->json([
            'request' => $request::class,
            'user' => $user,
            'count' => count(func_get_args()),
        ]);
    }

    public function throwNotFoundPrediction()
    {
        throw new ModelNotFoundException();
    }

    public function throwNotFound()
    {
        throw new Exception('xxxx');
    }

    public function passValuesBetweenMethodsPrediction()
    {
        $this->passToOutcome('PHP', 'JavaScript');
    }

    public function passValuesBetweenMethods()
    {
        [$backend, $frontend] = $this->resolvePrediction();

        return [
            'Laravel' => $backend,
            'Vue' => $frontend,
        ];
    }

    public function passValuesBetweenMethodsWithArgumentsPrediction($request)
    {
        $this->passToOutcome($request->input('q'));
    }

    public function passValuesBetweenMethodsWithArguments(Request $request)
    {
        [$q] = $this->resolvePrediction();

        return $q;
    }

    public function returnPassOnPrediction()
    {
        return $this->passToOutcome('foo');
    }

    public function returnPassOn()
    {
        throw new Exception('xxxx');
    }

    public function testResponseFromPredictionIsReturnedPrediction()
    {
        return response('expected-response');
    }

    public function testResponseFromPredictionIsReturned()
    {
        $this->resolvePrediction();

        throw new Exception('xxxx');
    }

    public function testResponseFromPredictionIsParsedToSymfonyResponsePrediction()
    {
        return ['expected' => 'response'];
    }

    public function testResponseFromPredictionIsParsedToSymfonyResponse()
    {
        $this->resolvePrediction();

        throw new Exception('xxxx');
    }

    public function testCanPassToOutcomeMultilpleTimesPrediction()
    {
        $this->passToOutcome('a');
        $this->passToOutcome('b', 'c');
    }

    public function testCanPassToOutcomeMultilpleTimes()
    {
        return $this->resolvePrediction();
    }
}

class PrecognitionTestRequest extends FormRequest
{
    public function rules()
    {
        return [
            'always' => 'integer',
            'whenNotPrecognitive' => $this->whenNotPrecognitive('integer'),
            'sometimes_1' => 'integer',
            'sometimes_2' => 'integer',
        ];
    }
}
