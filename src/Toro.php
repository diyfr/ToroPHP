<?php

class Toro
{
    public static function serve($routes)
    {
        ToroHook::fire('before_request', compact('routes'));

        $request_method = strtolower($_SERVER['REQUEST_METHOD']);
        $path_info = '/';
        if (!empty($_SERVER['PATH_INFO'])) {
            $path_info = $_SERVER['PATH_INFO'];
        }
        else if (!empty($_SERVER['ORIG_PATH_INFO']) && $_SERVER['ORIG_PATH_INFO'] !== '/index.php') {
            $path_info = $_SERVER['ORIG_PATH_INFO'];
        }
        else {
            if (!empty($_SERVER['REQUEST_URI'])) {
                $path_info = (strpos($_SERVER['REQUEST_URI'], '?') > 0) ? strstr($_SERVER['REQUEST_URI'], '?', true) : $_SERVER['REQUEST_URI'];
            }
        }
        
        $discovered_handler = null;
        $regex_matches = array();

        if (isset($routes[$path_info])) {
            $discovered_handler = $routes[$path_info];
        }
        else if ($routes) {
            $tokens = array(
                ':string' => '([a-zA-Z]+)',
                ':number' => '([0-9]+)',
                ':alpha'  => '([a-zA-Z0-9-_]+)'
            );
            foreach ($routes as $pattern => $handler_name) {
                $pattern = strtr($pattern, $tokens);
                if (preg_match('#^/?' . $pattern . '/?$#', $path_info, $matches)) {
                    $discovered_handler = $handler_name;
                    $regex_matches = $matches;
                    break;
                }
            }
        }

        /*
          |--------------------------------------------------------------------------
          | Handler static parameters
          |--------------------------------------------------------------------------
          |
          | Populate Handler methods(get(), post(), etc.) and hooks with parameters
          | defined in Query String style Handler name suffix
          |
          | For example:
          |
          |   Toro::serve(array(
          |     "/:string" => "MainHandler?param1=foo&param2=bar",
          |   ));
          |
          | ..with the route example above you can access `param1` and `param2`
          | via last parameter using their names in array fashion.
          | Assuming GET HTTP request is sent to `/woot`,
          | Handler may look like following:
          |
          |   class MainHandler {
          |     function __construct() {
          |       ToroHook::add("before_handler", function($toro) {
          |         $static_params = end($toro['regex_matches]);
          |         echo $static_params['param1']; // Output: foo
          |         echo $static_params['param2']; // Output: bar
          |       }
          |     }
          |
          |     function get($a, $b) {
          |       echo $a; // Output: woot
          |       echo $b['param1']; // Output: foo
          |       echo $b['param2']; // Output: bar
          |    }
          |   }
          |
         */

        if (is_string($discovered_handler) &&
            preg_match('/^[\w\\\]*\?([\w=&]*)$/', $discovered_handler, $matches)) {

            // Because first item in array with dynamic parameters returned by
            // preg_match() is cut before array is passed to Handler/Hook
            // method, we add a "duck" that will be cut instead of
            // static parameters array (that we'll add) in case array with
            // dynamic parameters is empty
            if( count($regex_matches) === 0 ) {
              array_push($regex_matches, null);
            }

            $static_parameters = explode('&', $matches[1]);
            array_walk($static_parameters, function($value, $key) use(&$static_parameters) {
              unset($static_parameters[$key]);
              list($param_name, $param_value) = array_values(explode('=', $value));
              $static_parameters[$param_name] = $param_value;
            });

            // Add array with static parameters to the end of array with
            // dynamic parameters
            array_push($regex_matches, $static_parameters);

            // Well, we can't leave Handler namespace name with query string
            // like that, right? Let's trim all the static parameters syntax off of it
            $discovered_handler = strstr($discovered_handler, '?', true);

        }

        $result = null;

        $handler_instance = null;
        if ($discovered_handler) {
            if (is_string($discovered_handler)) {
                $handler_instance = new $discovered_handler();
            }
            elseif (is_callable($discovered_handler)) {
                $handler_instance = $discovered_handler();
            }
        }

        if ($handler_instance) {
            unset($regex_matches[0]);

            if (self::is_xhr_request() && method_exists($handler_instance, $request_method . '_xhr')) {
                header('Content-type: application/json');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('Cache-Control: post-check=0, pre-check=0', false);
                header('Pragma: no-cache');
                $request_method .= '_xhr';
            }

            if (method_exists($handler_instance, $request_method)) {
                ToroHook::fire('before_handler', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
                $result = call_user_func_array(array($handler_instance, $request_method), $regex_matches);
                ToroHook::fire('after_handler', compact('routes', 'discovered_handler', 'request_method', 'regex_matches', 'result'));
            }
            else {
                ToroHook::fire('404', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
            }
        }
        else {
            ToroHook::fire('404', compact('routes', 'discovered_handler', 'request_method', 'regex_matches'));
        }

        ToroHook::fire('after_request', compact('routes', 'discovered_handler', 'request_method', 'regex_matches', 'result'));
    }

    private static function is_xhr_request()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }
}

class ToroHook
{
    private static $instance;

    private $hooks = array();

    private function __construct() {}
    private function __clone() {}

    public static function add($hook_name, $fn)
    {
        $instance = self::get_instance();
        $instance->hooks[$hook_name][] = $fn;
    }

    public static function fire($hook_name, $params = null)
    {
        $instance = self::get_instance();
        if (isset($instance->hooks[$hook_name])) {
            foreach ($instance->hooks[$hook_name] as $fn) {
                call_user_func_array($fn, array(&$params));
            }
        }
    }

    public static function get_instance()
    {
        if (empty(self::$instance)) {
            self::$instance = new ToroHook();
        }
        return self::$instance;
    }
}
