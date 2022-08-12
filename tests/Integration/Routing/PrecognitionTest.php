<?php

namespace Illuminate\Tests\Integration\Routing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Middleware\Precognition;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function testResponsesGeneratedViaExceptionBasedFlowControlHavePreparedHeaders()
    {
        $this->markTestIncomplete('wip');
    }

    public function testBeforeMiddleware()
    {
        // what happens when they return a response
        // what happens when they throw an exception
        // can they detect that precognition is active?
        $this->markTestIncomplete('wip');
    }

    public function testAfterMiddleware()
    {
        // what happens when they return a response
        // what happens when they throw an exception
        // can they detect that precognition is active?
        $this->markTestIncomplete('wip');
    }
}

class PrecognitionTestController
{
    public function predictUpdate()
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

    public function predictCheckPrecogMacro()
    {
        return request()->precognitive() ? 'yes' : 'no';
    }

    public function checkPrecogMacro()
    {
        return request()->precognitive() ? 'yes' : 'no';
    }

    public function checkArguments(Request $request, string $user)
    {
        throw new \Exception('xxxx');
    }

    public function predictCheckArguments($request, $user)
    {
        return [
            'request' => $request::class,
            'user' => $user,
            'count' => count(func_get_args()),
        ];
    }
}

class PrecognitionTestRequest extends FormRequest
{
    public function rules()
    {
        return [
            'always' => 'integer',
            'whenNotPrecognitive' => $this->whenNotPrecognitive('integer'),
        ];
    }
}
