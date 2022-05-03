<?php

namespace Illuminate\Routing\Matching;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

class UriValidator implements ValidatorInterface
{
    /**
     * Validate a given rule against a route and request.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function matches(Route $route, Request $request)
    {
        $path = rawurldecode(
            rtrim($request->getPathInfo(), '/') ?: '/'
        );

        if ($route->extensionRegex !== null) {
            if (preg_match($route->extensionRegex, $path) === 1) {
                $path = Str::beforeLast($path, '.');
            } elseif ($route->extensionRequired) {
                return false;
            }
        }

        return preg_match($route->getCompiled()->getRegex(), $path);
    }
}
