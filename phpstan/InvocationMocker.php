<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace PHPUnit\Framework\MockObject;

/**
 * PHPStan-only invocation mocker stub.
 */
interface InvocationMocker {

  public function method(string $constraint): self;

  public function with(mixed ...$arguments): self;

  public function will(mixed $stub): self;

  public function willReturn(mixed ...$values): self;

  public function willReturnCallback(callable $callback): self;

  public function willReturnMap(array $valueMap): self;

  public function willReturnOnConsecutiveCalls(mixed ...$values): self;

  public function willReturnSelf(): self;

  public function willThrowException(\Throwable $exception): self;

}
