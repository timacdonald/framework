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

        $response = $this->get('test-route', ['Precognition' => '1']);

        $response->assertNoContent();
        $response->assertHeader('Precognition', '1');
    }

    public function testItProvidesSensibleFinalResponseViaController()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'show'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => '1']);

        $response->assertNoContent();
        $response->assertHeader('Precognition', '1');
    }

    public function testItCanControlTheFinalResponseViaClosure()
    {
        Route::get('test-route', function () {
            throw new \Exception('xxxx');
        })->middleware(Precognition::class);

        Precognition::useResponseResolver(fn () => '🔮');
        $response = $this->get('test-route', ['Precognition' => '1']);

        $this->assertSame('🔮', $response->content());
        $response->assertHeader('Precognition', '1');
    }

    public function testItCanControlTheFinalResponseViaController()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'show'])
            ->middleware(Precognition::class);

        Precognition::useResponseResolver(fn () => '🔮');
        $response = $this->get('test-route', ['Precognition' => '1']);

        $this->assertSame('🔮', $response->content());
        $response->assertHeader('Precognition', '1');
    }

    public function testItCanImplementMagicMethodOnControllerToProvideResponse()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'update'])
            ->middleware(Precognition::class);

        $response = $this->get('test-route', ['Precognition' => '1']);

        $response->assertStatus(409);
        $this->assertSame('Conflict', $response->content());
        $response->assertHeader('Precognition', '1');
    }

    public function testControllerPredictionOverrulesFinalResponse()
    {
        Route::get('test-route', [PrecognitionTestController::class, 'update'])
            ->middleware(Precognition::class);
        Precognition::useResponseResolver(fn () => response('ok'));

        $response = $this->get('test-route', ['Precognition' => '1']);

        $response->assertStatus(409);
        $this->assertSame('Conflict', $response->content());
        $response->assertHeader('Precognition', '1');
    }

    public function testItBindsPrecognitiveStateToContainer()
    {
        Route::get('test-route', function () {
            throw new \Exception('xxxx');
        })->middleware(Precognition::class);

        $this->assertFalse($this->app->bound('precognitive'));

        $response = $this->get('test-route', ['Precognition' => '1']);

        $this->assertTrue($this->app->bound('precognitive'));
        $this->assertTrue($this->app['precognitive']);
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
}
