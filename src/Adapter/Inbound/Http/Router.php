<?php

declare(strict_types=1);

namespace FideX\Adapter\Inbound\Http;

/**
 * Minimal HTTP Router
 *
 * Zero-dependency front controller router for FideX.
 * Designed for cPanel/Apache compatibility.
 *
 * Routes are matched by METHOD + path pattern.
 * Supports path parameters: /api/v1/partners/{id}
 */
final class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Dispatch the current HTTP request to the matching route.
     */
    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Strip trailing slash (except root)
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            $params = $this->match($route['pattern'], $path);
            if ($params !== null) {
                ($route['handler'])($params);
                return;
            }
        }

        // No route matched
        $this->notFound();
    }

    /**
     * Match a path against a pattern.
     * Returns named params array on match, null on no match.
     *
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        // Convert {param} to named capture groups
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            // Return only string-keyed matches (named params)
            return array_filter($matches, fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => 'NOT_FOUND',
            'message' => 'The requested endpoint does not exist.',
        ]);
    }

    /**
     * Parse the JSON request body.
     *
     * @return array<string, mixed>
     */
    public static function parseJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * Get the Bearer token from the Authorization header.
     */
    public static function getBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        // Also check X-API-Key header
        return $_SERVER['HTTP_X_API_KEY'] ?? null;
    }

    /**
     * Send a JSON response.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('X-FideX-Node: fidex-php/1.0');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send a standard FideX error response.
     */
    public static function error(string $errorCode, string $message, int $statusCode): void
    {
        self::json([
            'error'   => $errorCode,
            'message' => $message,
        ], $statusCode);
    }
}
