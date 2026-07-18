<?php

namespace MacRadar\Core;

/**
 * Basit rota eşleştirici. {param} desteği ile.
 */
class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function get(string $p, callable $h): void { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void { $this->add('POST', $p, $h); }
    public function put(string $p, callable $h): void { $this->add('PUT', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $regex = $this->compile($route['pattern']);
            if (preg_match($regex, $request->path, $matches)) {
                foreach ($matches as $key => $val) {
                    if (!is_int($key)) {
                        $request->params[$key] = $val;
                    }
                }
                call_user_func($route['handler'], $request);
                return;
            }
        }
        Response::error('not_found', 'Kaynak bulunamadı: ' . $request->path, 404);
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }
}
