<?php

namespace Wolff\Core;

use Wolff\Core\Helper;
use Wolff\Utils\Str;
use Wolff\Exception\InvalidArgumentException;

/**
 * static @method get(string $url, $func, int $status = null)
 * static @method post(string $url, $func, int $status = null)
 * static @method put(string $url, $func, int $status = null)
 * static @method patch(string $url, $func, int $status = null)
 * static @method delete(string $url, $func, int $status = null)
 */
final class Route
{

    const STATUS_OK = 200;
    const STATUS_REDIRECT = 301;
    const GET_FORMAT = '/\{(.*)\}/';
    const OPTIONAL_GET_FORMAT = '/\{(.*)\?\}/';
    const PREFIXES = [
        'csv:'   => 'text/csv',
        'json:'  => 'application/json',
        'pdf:'   => 'application/pdf',
        'plain:' => 'text/plain',
        'xml:'   => 'application/xml'
    ];
    const HTTP_METHODS = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE'
    ];

    /**
     * List of routes
     *
     * @var array
     */
    private static $routes = [];

    /**
     * List of routes for status codes
     */
    private static $codes = [];

    /**
     * List of blocked routes
     *
     * @var array
     */
    private static $blocked = [];

    /**
     * List of redirects
     *
     * @var array
     */
    private static $redirects = [];


    /**
     * Proxy method to the HTTP Methods.
     *
     * @param  string  $name  the method name
     * @param  mixed  $args  the method arguments
     */
    public static function __callStatic(string $name, $args): void
    {
        $http_method = strtoupper($name);
        if (!in_array($http_method, self::HTTP_METHODS)) {
            return;
        }

        if (!isset($args[0]) || !is_string($args[0])) {
            throw new InvalidArgumentException('url', 'of type string');
        } elseif (!isset($args[1]) || (!is_string($args[1]) && !($args[1] instanceof \Closure))) {
            throw new InvalidArgumentException('function', 'of type string or an instance of \Closure');
        }

        $status = isset($args[2]) && is_numeric($args[2]) ?
            (int)$args[2] :
            null;

        self::addRoute(Str::sanitizeURL($args[0]), $http_method, $args[1], $status);
    }


    /**
     * Adds a route that renders a view
     *
     * @param  string  $url  the url
     * @param  string  $view_path  the view path
     * @param  array  $data  the view data
     * @param  bool  $cache  use or not the cache system
     */
    public static function view(string $url, string $view_path, array $data = [], bool $cache = true): void
    {
        $func = function () use ($view_path, $data, $cache) {
            View::render($view_path, $data, $cache);
        };

        self::addRoute($url, 'GET', $func, null);
    }


    /**
     * Adds a route that will work
     * only for a status code
     *
     * @param  int  $code  the status code
     * @param  \Closure  $func  mixed the function that must be executed
     * when getting the status code
     */
    public static function code(int $code, \Closure $func): void
    {
        self::$codes[$code] = $func;
    }


    /**
     * Executes a code route based on the current status code
     *
     * @param  \Wolff\Core\Http\Request  $req  reference to the current request object
     * @param  \Wolff\Core\Http\Response  $res  reference to the current response object
     */
    public static function execCode(Http\Request &$req, Http\Response &$res): void
    {
        $code = http_response_code();

        if (isset(self::$codes[$code]) && is_callable(self::$codes[$code])) {
            call_user_func_array(self::$codes[$code], [
                $req,
                $res
            ]);
        }
    }


    /**
     * Returns the value of a route
     *
     * @param  string  $url  the url
     *
     * @return mixed the value associated to the route
     */
    public static function getFunction(string $url)
    {
        $current = array_filter(explode('/', $url));
        $len = count($current) - 1;

        foreach (self::$routes as $key => $val) {
            if (!self::isValidRoute($key)) {
                continue;
            }

            $route = array_filter(explode('/', $key));

            if (self::matchesRoute($current, $len, $route, count($route) - 1)) {
                return self::processRoute($current, $key, $route);
            }
        }

        return null;
    }


    /**
     * Returns true if the current route matches the given one, false
     * otherwise
     *
     * @param  array  $current  the current route array
     * @param  int  $current_len  the size of the current route array
     * @param  array  $route  the route array to test
     * @param  int  $route_len  the size of the route array to test
     *
     * @return bool true if the current route matches the given one, false
     * otherwise
     */
    private static function matchesRoute(array $current, int $current_len, array $route, int $route_len)
    {
        if (empty($current) && empty($route)) {
            return true;
        }

        for ($i = 0; $i <= $route_len && $i <= $current_len; $i++) {
            if ($current[$i] !== $route[$i] && !self::isGet($route[$i])) {
                break;
            }

            if (($i === $route_len || ($i + 1 === $route_len && self::isOptionalGet($route[$i + 1]))) &&
                $i === $current_len) {
                return true;
            }
        }

        return false;
    }


    /**
     * Returns the route function after
     * setting the HTTP response code and the content-type
     * based on the route
     *
     * @param  array  $current  the current route array (exploded by /)
     * @param  string  $key  the route key (the route array imploded)
     * @param  array  $route  the registered route array which matches the
     * current route (exploded by /)
     *
     * @return mixed the route function
     */
    private static function processRoute(array $current, string $key, array $route)
    {
        self::mapParameters($current, $route);

        $route = self::$routes[$key];
        header("Content-Type: $route[content_type]");
        if (isset($route['status'])) {
            http_response_code($route['status']);
        }

        return $route['function'];
    }


    /**
     * Maps the current route GET parameters
     *
     * @param  array  $current  the current route array
     * (exploded by /)
     * @param  array  $route  the registered route array
     * (exploded by /) which matches the current route
     */
    private static function mapParameters(array $current, array $route)
    {
        $current_len = count($current) - 1;
        $route_len = count($route) - 1;

        for ($i = 0; $i <= $route_len && $i <= $current_len; $i++) {
            if (self::isOptionalGet($route[$i])) {
                self::setOptionalGetVar($route[$i], $current[$i]);
            } elseif (self::isGet($route[$i])) {
                self::setGet($route[$i], $current[$i]);
            }

            //Finish if last GET variable from url is optional
            if ($i + 1 === $route_len && $i === $current_len &&
                self::isOptionalGet($route[$i + 1])) {
                self::setOptionalGetVar($route[$i], $current[$i]);
                return;
            }
        }
    }


    /**
     * Returns true if the route exists and its
     * request method matches the current methods
     *
     * @param  string  $key  the route key
     *
     * @return bool true if the route exists and its
     * request method matches the current methods
     */
    private static function isValidRoute($key): bool
    {
        return self::$routes[$key]['method'] === '' ||
            self::$routes[$key]['method'] === $_SERVER['REQUEST_METHOD'];
    }


    /**
     * Adds a route that works for any method
     *
     * @param  string  $url  the url
     * @param  mixed  $func  mixed the function that must be executed when accessing the route
     * @param  int  $status  the HTTP response code
     */
    public static function any(string $url, $func, int $status = self::STATUS_OK): void
    {
        self::addRoute(Str::sanitizeURL($url), '', $func, $status);
    }


    /**
     * Redirects the first url to the second url
     *
     * @param  string  $from  the origin url
     * @param  string  $to  the destiny url
     * @param  int  $code  The HTTP response code
     */
    public static function redirect(string $from, string $to, int $code = self::STATUS_REDIRECT): void
    {
        self::$redirects[Str::sanitizeURL($from)] = [
            'destiny' => Str::sanitizeURL($to),
            'code'    => $code
        ];
    }


    /**
     * Adds a route to the list
     *
     * @param  mixed  $url  the url
     * @param  string  $method  the url HTTP method
     * @param  mixed  $function  the url function or controller name
     * @param  int|null  $status  the HTTP response code
     */
    private static function addRoute($url, string $method, $function, $status): void
    {
        $content_type = 'text/html';

        //Remove content-type prefix from route
        foreach (self::PREFIXES as $key => $val) {
            if (strpos($url, $key) === 0) {
                $url = substr($url, strlen($key));
                $content_type = $val;
            }
        }

        self::$routes[trim($url, '/')] = [
            'function'     => $function,
            'method'       => $method,
            'status'       => $status,
            'content_type' => $content_type,
        ];
    }


    /**
     * Blocks an url
     *
     * @param  string  $url  the url
     */
    public static function block(string $url): void
    {
        array_push(self::$blocked, Str::sanitizeURL($url));
    }


    /**
     * Check if an url is blocked
     *
     * @param  string  $url  the url
     *
     * @return bool true if the url is blocked, false otherwise
     */
    public static function isBlocked(string $url): bool
    {
        $url = explode('/', $url);
        $url_len = count($url) - 1;

        foreach (self::$blocked as $blocked) {
            if (Helper::matchesRoute($blocked, $url, $url_len)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Check if a route exists
     *
     * @param  string  $url  the url
     *
     * @return bool true if the route exists, false otherwise
     */
    public static function exists(string $url): bool
    {
        $url = preg_replace(self::GET_FORMAT, '{}', $url);
        $routes = [];

        foreach (array_keys(self::$routes) as $key) {
            $routes[] = preg_replace(self::GET_FORMAT, '{}', $key);
        }

        return in_array($url, $routes);
    }


    /**
     * Returns true if a string has the format of a GET variable, false otherwise
     *
     * @param  string  $str  the string
     *
     * @return bool true if the string has the format of a route GET variable, false otherwise
     */
    private static function isGet(string $str): bool
    {
        return preg_match(self::GET_FORMAT, $str);
    }


    /**
     * Returns true if a string has the format of an optional GET variable, false otherwise
     *
     * @param  string  $str  the string
     *
     * @return bool true if the string has the format of an optional route GET variable, false otherwise
     */
    private static function isOptionalGet(string $str): bool
    {
        return preg_match(self::OPTIONAL_GET_FORMAT, $str);
    }


    /**
     * Set a GET variable
     *
     * @param  string  $key  the variable key
     * @param  string  $value  the variable value
     */
    private static function setGet(string $key, $value): void
    {
        $key = preg_replace(self::GET_FORMAT, '$1', $key);
        $_GET[$key] = $value;
    }


    /**
     * Set an optional GET variable
     *
     * @param  string  $key  the variable key
     * @param  string  $value  the variable value
     */
    private static function setOptionalGetVar(string $key, $value = null): void
    {
        $key = preg_replace(self::OPTIONAL_GET_FORMAT, '$1', $key);
        $_GET[$key] = $value ?? '';
    }


    /**
     * Returns all the available routes
     * @return array the available routes
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }


    /**
     * Returns all the available redirects
     * @return array the available redirects
     */
    public static function getRedirects(): array
    {
        return self::$redirects;
    }


    /**
     * Returns the redirection of the specified route
     *
     * @param  string  $url  the route url
     *
     * @return array|null the redirection url
     * or null if the specified route doesn't have a redirection
     */
    public static function getRedirection(string $url): ?array
    {
        return self::$redirects[$url] ?? null;
    }


    /**
     * Returns all the blocked routes
     * @return array the blocked routes
     */
    public static function getBlocked(): array
    {
        return self::$blocked;
    }
}
