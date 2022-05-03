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
        $path = $fullPath = rawurldecode(
            rtrim($request->getPathInfo(), '/') ?: '/'
        );

        if ($route->extensionRegex !== null) {
            $path = preg_replace($route->extensionRegex, '', $path);
        }

        if ($route->extensionRequired && $path === $fullPath) {
            return false;
        }

        return preg_match($route->getCompiled()->getRegex(), $path);
    }
}
