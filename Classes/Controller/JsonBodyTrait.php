<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Controller;

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

        $content = (string) $request->getBody();
        if ($content === '') {
            return [];
        }

        $decoded = \json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
