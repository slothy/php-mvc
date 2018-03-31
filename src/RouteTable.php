<?php
namespace PhpMvc;

/**
 * Stores the URL routes for an application.
 */
class RouteTable {

    /**
     * Gets or sets a collection of routes.
     * 
     * @var Route[]
     */
    private static $routes = array();

    /**
     * Adds a rule.
     * 
     * @param Route|string $route A rule to add. 
     * 
     * @return void
     */
    public static function Add($route) {
        if (is_string($route)) {
            $template = $route;
            $route = new Route();
            $route->template = $template;
        }

        if (!$route instanceof Route) {
            throw new \Exception('A type of Route or a derivative is expected.');
        }

        self::$routes[] = $route;
    }

    /**
     * Adds a rule.
     * 
     * @return void
     */
    public static function AddRoute($name, $template, $defaults = null, $constraints = null) {
        $route = new Route();

        $route->name = $name;
        $route->template = $template;
        $route->defaults = $defaults;
        $route->constraints = $constraints;

        self::$routes[] = $route;
    }

    /**
     * Returns the first route similar to the current url.
     * 
     * @return Route
     */
    public static function getRoute() {
        $path = trim($_SERVER['REQUEST_URI'], '/');
        $queryString = array();

        if (($qsIndex = strpos($path, '?')) !== false) {
            parse_str($_SERVER['QUERY_STRING'], $queryString);
            $path = substr($path, 0, $qsIndex);
        }

        foreach(self::$routes as $route) {
            // escape special characters
            $safeTemplate = self::escapeTemplate($route->template);

            // make patterns for each path segments
            $segments = self::getSegmentPatterns($route, $safeTemplate);

            // make final pattern and url to test
            $pattern = $url = '';

            foreach ($segments as $segment) {
                if (!empty($segment['before'])) {
                    $pattern .= $segment['before'];
                    $url .= str_replace('\\', '', $segment['before']);
                }

                $pattern .= $segment['pattern'];

                if (!empty($segment['after'])) {
                    $pattern .= $segment['after'];
                    $url .= str_replace('\\', '', $segment['after']);
                }

                if (!empty($segment['name']) && !empty($queryString[$segment['name']])) {
                    $url .= $queryString[$segment['name']];
                }

                if (empty($segment['glued'])) {
                    if ($segment['optional'] || !empty($segment['end']) || !empty($segment['pre-end'])) {
                        $pattern .= '(\/|)';
                    }
                    else {
                        $pattern .= '\/';
                    }

                    $url .= '/';
                }
            }

            $url = rtrim($url, '/');

            // test url
            if (preg_match('/^' . $pattern . '$/si', $url, $matches) === 1) {
                // match is found
                // filling the values
                $values = array();

                foreach ($segments as $segment) {
                    if (!empty($matches[$segment['name']])) {
                        $values[$segment['name']] = $matches[$segment['name']];
                    }
                }

                $route->values = $values;

                return $route;
            }
        }

        return null;
    }

    /**
     * @return string
     */
    private static function escapeTemplate($value) {
        return preg_replace_callback('/([^\{\/\x5c]+|)(\{[^\}]+\})([^\{\/\x5c]+|)/', function($m) {
            array_shift($m);

            $m = array_filter($m, function($item) {
                return !empty($item);
            });

            $m = array_map(function($item) {
                return mb_substr($item, 0, 1) !== '{' ? preg_quote($item, '/') : $item;
            }, $m);

            return implode('', $m);
        }, $value);
    }

    /**
     * 
     * @param Route $route
     * @param string $url
     * 
     * @return array
     */
    private static function getSegmentPatterns($route, $template) {
        $segments = array();

        if (preg_match_all('/\{([^\}]+)\}/', $template, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            $prevMatchString = $prevName = '';
            $prevIndex = 0;

            for ($i = 0, $count = count($matches); $i < $count; ++$i) {
                $matchString = $matches[$i][0][0];
                $name  = $matches[$i][1][0];
                $index = $matches[$i][0][1];
                $before = $prevAfter = '';
                $prevGlued = false;
                $segmentPattern = '';
                $optional = false;
                $default = null;
                
                // parse name
                if (count($name = explode('=', $name)) > 1) {
                    $default = $name[1];
                    $name = $name[0];
                }
                else {
                    $name = $name[0];
                }

                // check optional marker
                if (count($name = explode('?', $name)) > 1) {
                    $default = UrlParameter::OPTIONAL;
                    $name = $name[0];
                }
                else {
                    $name = $name[0];
                }

                // default value is allowed or not
                if (!empty($default)) {
                    if (!empty($route->defaults[$name])) {
                        throw new \Exception('The route parameter "' . $name . '" has both an inline default value and an explicit default value specified. A route parameter cannot contain an inline default value when a default value is specified explicitly. Consider removing one of them.');
                    }
                    else {
                        $route->defaults[$name] = $default;
                    }
                }

                // before and after text
                if ($index > $prevIndex + ($prevLen = mb_strlen($prevMatchString))) {
                    $before = mb_substr($template, $prevIndex + $prevLen, $index - $prevIndex - $prevLen);
                }

                $prevGlued = !empty($before) && $before != '/';

                if (count($parts = explode('/', $before)) > 1) {
                    $prevAfter = $parts[0];
                    $before = $parts[1];
                }

                if (!empty($prevAfter)) {
                    $segments[$i - 1]['after'] = $prevAfter;
                }

                if ($prevGlued) {
                    $segments[$i - 1]['glued'] = true;
                }

                // make pattern
                if (!empty($route->constraints[$name])) {
                    if (!empty($route->defaults[$name]) && $route->defaults[$name] === UrlParameter::OPTIONAL) {
                        $segmentPattern = '(?<' . $name . '>(' . $route->constraints[$name] . ')|)';
                        $optional = true;
                    }
                    else {
                        $segmentPattern = '(?<' . $name . '>' . $route->constraints[$name] . ')';
                    }
                }
                else {
                    if (!empty($route->defaults[$name]) && $route->defaults[$name] === UrlParameter::OPTIONAL) {
                        $segmentPattern = '(?<' . $name . '>[^\/]*)';
                        $optional = true;
                    }
                    else {
                        $segmentPattern = '(?<' . $name . '>[^\/]+)';
                    }
                }

                // result
                $segment = array(
                    'name' => $name,
                    'pattern' => $segmentPattern,
                    'optional' => $optional
                );
                
                if (!empty($before)) {
                    $segment['before'] = $before;
                }

                $segments[] = $segment;

                $prevName = $name;
                $prevIndex = $index;
                $prevMatchString = $matchString;
            }
        }
        else {
            $segments[] = array(
                'pattern' => $template,
                'optional' => false,
            );
        }

        self::setBorders($segments);

        return $segments;
    }

    private static function setBorders(&$segments) {
        for ($i = 0, $count = count($segments); $i < $count; ++$i) {
            if ($segments[$i]['optional'] && $i - 1 > 0) {
                $optional = true;

                for ($j = $i; $j < $count; ++$j) {
                    if (!$segments[$j]['optional']) {
                        $optional = false;
                        break;
                    }
                }

                if ($optional) {
                    $segments[$i - 1]['pre-end'] = true;
                }
            }

            if ($i + 1 == $count) {
                $segments[$i]['end'] = true;
            }
        }
    }

}