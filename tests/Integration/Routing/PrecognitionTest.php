<?php

namespace Illuminate\Tests\Integration\Routing;

use Illuminate\Foundation\Http\Middleware\Precognition;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class PrecognitionTest extends TestCase
{
    public function testItProvidesSensibleFinalResponseViaClosure()
    {
        Route::get('test-route', function () {
            throw new \Exception('xxxx');
        })->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => 'true']);

        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');
    }

    public function testItProvidesSensibleFinalResponseViaController()
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

    public function testItBindRequestMacro()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'checkPrecogMacro'])
            ->middleware(Precognition::class);

        $responses = [];
        $responses[] = $this->get('test-route')->content();
        $responses[] = $this->get('test-route', ['Precognition' => 'true'])->content();

        $this->assertSame(['no', 'yes'], $responses);
    }

    public function testBeforeMiddleware()
    {
        // what happens when they return a response
        // what happens when they throw an exception
        // can they detect that precognition is active?
    }

    public function testAfterMiddleware()
    {
        // what happens when they return a response
        // what happens when they throw an exception
        // can they detect that precognition is active?
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
}
