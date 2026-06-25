<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace PHPUnit\Framework\MockObject;

/**
 * PHPStan-only mock builder stub.
 *
 * @template T of object
 */
interface MockBuilder {

  /**
   * @param string[] $methods
   *
   * @return $this
   */
  public function addMethods(array $methods): self;

  /**
   * @return $this
   */
  public function disableOriginalConstructor(): self;

  /**
   * @param string[] $methods
   *
   * @return $this
   */
  public function onlyMethods(array $methods): self;

  /**
   * @return MockObject&T
   */
  public function getMock(): MockObject;

}
