<?php

namespace Illuminate\Tests\Integration\Foundation;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\PhpProcess;

class ExceptionHandlerTest extends TestCase
{
    /**
     * Resolve application HTTP exception handler.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function resolveApplicationExceptionHandler($app)
    {
        $app->singleton('Illuminate\Contracts\Debug\ExceptionHandler', 'Illuminate\Foundation\Exceptions\Handler');
    }

    public function testItRendersAuthorizationExceptions()
    {
        Route::get('test-route', fn () => Response::deny('expected message', 321)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(403)
            ->assertSeeText('expected message');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'expected message',
            ]);
    }

    public function testItRendersAuthorizationExceptionsWithCustomStatusCode()
    {
        Route::get('test-route', fn () => Response::deny('expected message', 321)->withStatus(404)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(404)
            ->assertSeeText('Not Found');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'expected message',
            ]);
    }

    public function testItRendersAuthorizationExceptionsWithStatusCodeTextWhenNoMessageIsSet()
    {
        Route::get('test-route', fn () => Response::denyWithStatus(404)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(404)
            ->assertSeeText('Not Found');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'Not Found',
            ]);

        Route::get('test-route', fn () => Response::denyWithStatus(418)->authorize());

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(418)
            ->assertSeeText("I'm a teapot", false);

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(418)
            ->assertExactJson([
                'message' => "I'm a teapot",
            ]);
    }

    public function testItRendersAuthorizationExceptionsWithStatusButWithoutResponse()
    {
        Route::get('test-route', fn () => throw (new AuthorizationException())->withStatus(418));

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(418)
            ->assertSeeText("I'm a teapot", false);

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(418)
            ->assertExactJson([
                'message' => "I'm a teapot",
            ]);
    }

    public function testItHasFallbackErrorMessageForUnknownStatusCodes()
    {
        Route::get('test-route', fn () => throw (new AuthorizationException())->withStatus(399));

        // HTTP request...
        $this->get('test-route')
            ->assertStatus(399)
            ->assertSeeText('Whoops, looks like something went wrong.');

        // JSON request...
        $this->getJson('test-route')
            ->assertStatus(399)
            ->assertExactJson([
                'message' => 'Whoops, looks like something went wrong.',
            ]);
    }

    /**
     * @dataProvider exitCodesProvider
     */
    public function testItReturnsNonZeroExitCodesForUncaughtExceptions($providers, $successful)
    {
        $basePath = static::applicationBasePath();
        $providers = json_encode($providers, true);

        $process = new PhpProcess(<<<EOF
<?php

require 'vendor/autoload.php';

\$laravel = Orchestra\Testbench\Foundation\Application::create(basePath: '$basePath', options: ['extra' => ['providers' => $providers]]);
\$laravel->singleton('Illuminate\Contracts\Debug\ExceptionHandler', 'Illuminate\Foundation\Exceptions\Handler');

\$kernel = \$laravel[Illuminate\Contracts\Console\Kernel::class];

return \$kernel->call('throw-exception-command');
EOF, __DIR__.'/../../../', ['APP_RUNNING_IN_CONSOLE' => true]);

        $process->run();

        $this->assertSame($successful, $process->isSuccessful());
    }

    public static function exitCodesProvider()
    {
        yield 'Throw exception' => [[Fixtures\Providers\ThrowUncaughtExceptionServiceProvider::class], false];
        yield 'Do not throw exception' => [[Fixtures\Providers\ThrowExceptionServiceProvider::class], true];
    }

    public function testItDoesExposesExceptionMessageInDebugMode()
    {
        Config::set('app.debug', true);

        Route::get('model-not-found', fn () => throw (new ModelNotFoundException)->setModel('App\\Models\\User', 55));

        $this->getJson('model-not-found')
            ->assertStatus(404)
            ->assertJson([
                'message' => 'No query results for model [App\\Models\\User] 55',
            ]);

        Route::get('backed-enum-case-not-found', fn () => throw new BackedEnumCaseNotFoundException('App\\Enums\\UserType', 'superadmin'));

        $this->getJson('backed-enum-case-not-found')
            ->assertStatus(404)
            ->assertJson([
                'message' => 'Case [superadmin] not found on Backed Enum [App\\Enums\\UserType].',
            ]);

        Route::get('records-not-found', fn () => throw new RecordsNotFoundException('No database records were found'));

        $this->getJson('records-not-found')
            ->assertStatus(404)
            ->assertJson([
                'message' => 'Not found.',
            ]);

        Route::get('multiple-records-not-found', fn () => throw new MultipleRecordsFoundException(55));

        $this->getJson('multiple-records-not-found')
            ->assertStatus(500)
            ->assertJson([
                'message' => '55 records were found.',
            ]);
    }

    public function testItDoesNotExposeModelInNonDebugMode()
    {
        Config::set('app.debug', false);

        Route::get('model-not-found', fn () => throw (new ModelNotFoundException)->setModel('App\\Models\\User', 55));

        $this->getJson('model-not-found')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'Not found.',
            ]);

        Route::get('backed-enum-case-not-found', fn () => throw new BackedEnumCaseNotFoundException('App\\Enums\\UserType', 'superadmin'));

        $this->getJson('backed-enum-case-not-found')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'Not found.',
            ]);

        Route::get('records-not-found', fn () => throw new RecordsNotFoundException('No database records were found'));

        $this->getJson('records-not-found')
            ->assertStatus(404)
            ->assertExactJson([
                'message' => 'Not found.',
            ]);

        Route::get('multiple-records-not-found', fn () => throw new MultipleRecordsFoundException(55));

        $this->getJson('multiple-records-not-found')
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Server Error',
            ]);
    }
}
