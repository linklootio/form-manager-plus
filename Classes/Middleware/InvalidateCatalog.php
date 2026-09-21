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
use SchefferWebdesign\FormManagerPlus\Service\FormCatalog;

final class InvalidateCatalog implements MiddlewareInterface
{
    public function __construct(private FormCatalog $catalog) {}
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }
        $route = (string)$request->getAttribute('route')?->getOption('_identifier');
        if (!str_contains(strtolower($route), 'form')) {
            return $handler->handle($request);
        }
        if ($route === 'ajax_form_manager_plus_tools') {
            $raw = (string)$request->getBody();
            $input = strlen($raw) <= 5 * 1024 * 1024 ? json_decode($raw, true) : null;
            if (is_array($input) && in_array($input['op'] ?? '', ['favorite', 'profile_save', 'view_save', 'view_delete', 'check', 'preview'], true)) {
                return $handler->handle($request);
            }
        }
        try {
            return $handler->handle($request);
        } finally {
            $this->catalog->clear();
        }
    }
}
