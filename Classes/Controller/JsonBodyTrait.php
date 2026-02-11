<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;

trait JsonBodyTrait
{
    /**
     * @return array<string, mixed>
     */
    private function getJsonBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (\is_array($body)) {
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

        return \is_array($decoded) ? $decoded : [];
    }
}
