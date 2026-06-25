<?php

declare(strict_types=1);

// phpcs:ignoreFile

namespace PHPUnit\Framework;

use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * PHPStan-only stub for projects that do not install PHPUnit in runtime vendor.
 */
abstract class TestCase {

  /**
   * @template T of object
   *
   * @param class-string<T> $originalClassName
   *
   * @return MockObject&T
   */
  public function createMock(string $originalClassName): MockObject {
    throw new \LogicException('PHPStan stub.');
  }

  /**
   * @template T of object
   *
   * @param class-string<T> $type
   *
   * @return MockBuilder<T>
   */
  public function getMockBuilder(string $type): MockBuilder {
    throw new \LogicException('PHPStan stub.');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
  }

  /**
   * @template T of object
   *
   * @param class-string<T> $originalClassName
   * @param array<string, mixed> $configuration
   *
   * @return MockObject&T
   */
  public function createConfiguredMock(string $originalClassName, array $configuration): MockObject {
    throw new \LogicException('PHPStan stub.');
  }

  public function assertArrayHasKey(mixed $key, mixed $array, string $message = ''): void {
  }

  public function assertArrayNotHasKey(mixed $key, mixed $array, string $message = ''): void {
  }

  public function assertContains(mixed $needle, iterable $haystack, string $message = ''): void {
  }

  public function assertCount(int $expectedCount, mixed $haystack, string $message = ''): void {
  }

  public function assertEmpty(mixed $actual, string $message = ''): void {
  }

  public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void {
  }

  public function assertEqualsWithDelta(mixed $expected, mixed $actual, float $delta, string $message = ''): void {
  }

  public function assertFalse(mixed $condition, string $message = ''): void {
  }

  public function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void {
  }

  public function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void {
  }

  public function assertIsArray(mixed $actual, string $message = ''): void {
  }

  public function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void {
  }

  public function assertNotEmpty(mixed $actual, string $message = ''): void {
  }

  public function assertNotNull(mixed $actual, string $message = ''): void {
  }

  public function assertNull(mixed $actual, string $message = ''): void {
  }

  public function assertSame(mixed $expected, mixed $actual, string $message = ''): void {
  }

  public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void {
  }

  public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void {
  }

  public function assertDoesNotMatch(string $pattern, string $string, string $message = ''): void {
  }

  public function assertTrue(mixed $condition, string $message = ''): void {
  }

  public function expectException(string $exception): void {
  }

  public function expectExceptionMessage(string $message): void {
  }

  public function once(): mixed {
    return NULL;
  }

  public function never(): mixed {
    return NULL;
  }

  public function any(): mixed {
    return NULL;
  }

  public function exactly(int $count): mixed {
    return NULL;
  }

  public function atLeast(int $count): mixed {
    return NULL;
  }

  public function anything(): mixed {
    return NULL;
  }

  public function callback(callable $callback): mixed {
    return NULL;
  }

  public function stringContains(string $string): mixed {
    return NULL;
  }

}
