<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Unit\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds a form-encoded request whose parsed body is returned verbatim.
 *
 * The JSON request helpers in the controller tests run their fixture through
 * json_encode for the body stream, which throws on invalid UTF-8 and therefore
 * cannot express a body carrying raw bytes. PHP populates $_POST from a
 * form-encoded request without validating encoding, and getJsonBody() hands that
 * array straight back, so this is the shape needed to cover the encoding guard.
 */
trait FormRequestTrait
{
    /**
     * @param array<string, mixed> $data
     */
    private function createFormRequest(array $data): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request
            ->method('getParsedBody')
            ->willReturn($data);

        return $request;
    }
}
