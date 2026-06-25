<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace PHPUnit\Framework\MockObject;

/**
 * PHPStan-only mock object stub.
 */
interface MockObject {

  public function expects(mixed $matcher): InvocationMocker;

  public function method(string $constraint): InvocationMocker;

}
