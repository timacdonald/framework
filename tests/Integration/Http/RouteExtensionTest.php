<?php

namespace Illuminate\Tests\Integration\Http;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class RouteExtensionTest extends TestCase
{
    public function testIt404sWithRequiredExtensions()
    {
        Route::get('users/user', function () {
            return 'ok';
        })->requiredExtensions(['csv']);

        $this->get('users/user')->assertNotFound();
        $this->get('users/user.csv')->assertOk();
        $this->get('users/user.json')->assertNotFound();
        $this->get('users/user.json.csv')->assertNotFound();
    }

    public function testIt404sWithOptionalExtensions()
    {
        Route::get('users/user', function () {
            return 'ok';
        })->optionalExtensions(['csv']);

        $this->get('users/user')->assertOk();
        $this->get('users/user.csv')->assertOk();
        $this->get('users/user.json')->assertNotFound();
        $this->get('users/user.json.csv')->assertNotFound();
    }

    public function testItWorksWithRouteModelBinding()
    {
        Route::get('users/{user}', function ($user) {
            return 'id-'.$user;
        })->requiredExtensions(['csv']);

        $response = $this->get('users/22.csv');

        $response->assertOk();
        $response->assertSeeText('id-22');
    }

    public function testItCanRetrieveTheExtensionFromTheRequest()
    {
        Route::get('users/{user}', function () {
            return 'extension-'.request()->extension();
        })->optionalExtensions(['csv']);

        $this->get('users/22.json.csv')->assertSeeText('extension-csv');;
        $this->get('users/22')->assertSeeText('extension-');;
    }

    public function testItCanRetrieveAnEmptyExtensionFromTheRootUrl()
    {
        Route::get('/', function () {
            return 'extension-'.request()->extension();
        });

        $this->get('/')->assertSeeText('extension-');;
    }
}
