<?php

namespace App\Middleware;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Logging middleware
 */
class LoggingMiddleware
{

    /**
     * Invoke method.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @param \Psr\Http\Message\ResponseInterface $response The response.
     * @param callable $next Callback to invoke the next middleware.
     * @return \Psr\Http\Message\ResponseInterface A response
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, $next)
    {
        $response = $next($request, $response);

        $statusCode = $response->getStatusCode();
        if (startsWith($request->getRequestTarget(), '/logs') || in_array($statusCode, [200]))
            return $response;

        $request_body = json_decode($request->getBody()->getContents(), true);
        $data = [
            'user_id' => Configure::read('user_id'),
            'ip_address' => $request->clientIp(),
            'request_method' => $request->getMethod(),
            'request_url' => $request->getRequestTarget(),
            'request_headers' => json_encode($request->getHeaders()),
            'request_body' => $request_body ? json_encode($request_body) : null,
            'status_code' => $statusCode
        ];

        $connection = ConnectionManager::get('default');
        $connection->insert('logs', $data);

        return $response;
    }
}
