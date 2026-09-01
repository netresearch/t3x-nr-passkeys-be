<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;

trait JsonBodyTrait
{
    /**
     * Re-encode one section of the parsed body (the WebAuthn assertion or credential
     * object) as the JSON string the ceremony services expect.
     *
     * Returns '' when the section is absent or not an object, and null when it cannot
     * be encoded. That second case is not theoretical: a form-encoded request reaches
     * getParsedBody() as the raw bytes PHP put in $_POST, so a value that is not valid
     * UTF-8 makes json_encode throw JsonException — which extends \Exception, not
     * RuntimeException, and so escaped the controllers' own catch blocks and surfaced
     * as an uncaught-exception 500 on a public endpoint.
     */
    private function encodeBodySection(mixed $value): ?string
    {
        if (!\is_array($value)) {
            return '';
        }

        try {
            return \json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        if (\is_array($body)) {
            /** @var array<string, mixed> $body */
            return $body;
        }

        $contentType = $request->getHeaderLine('Content-Type');

        if ($contentType !== '' && !\str_contains($contentType, 'application/json')) {
            return [];
        }

        $content = (string) $request->getBody();

        if ($content === '') {
            return [];
        }

        try {
            $decoded = \json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
