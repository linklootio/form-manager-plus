<?php

declare(strict_types=1);

/*
 * Form Manager Plus. SPDX-License-Identifier: GPL-2.0-or-later. See LICENSE.
 */

namespace SchefferWebdesign\FormManagerPlus\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SchefferWebdesign\FormManagerPlus\Service\WorkspaceData;

final class RecentEdits implements MiddlewareInterface
{
    public function __construct(private WorkspaceData $data) {}
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $route = (string)$request->getAttribute('route')?->getOption('_identifier');
        if ($request->getMethod() === 'POST' && str_contains(strtolower($route), 'saveform') && $response->getStatusCode() === 200) {
            $input = $request->getParsedBody() ?: [];
            if (!$input && str_contains($request->getHeaderLine('Content-Type'), 'json')) {
                $input = json_decode((string)$request->getBody(), true) ?: [];
            }
            $identifier = $input['formPersistenceIdentifier'] ?? $request->getQueryParams()['formPersistenceIdentifier'] ?? null;
            $result = json_decode((string)$response->getBody(), true);
            $result = $result['response'] ?? $result;
            if (is_string($identifier) && strlen($identifier) <= 1024 && ($result['status'] ?? '') === 'success') {
                $this->data->edited($identifier);
            }
        }
        return $response;
    }
}
