<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_content_audit\Unit\Service;

use Drupal\ai_content_audit\Service\HtmlFetchService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for HtmlFetchService.
 *
 * @group ai_content_audit
 * @coversDefaultClass \Drupal\ai_content_audit\Service\HtmlFetchService
 */
class HtmlFetchServiceTest extends TestCase {

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds a mock HTTP response whose body is the given string.
   *
   * @param string $body
   *   Response body content.
   * @param int $statusCode
   *   HTTP status code (default 200).
   */
  private function buildResponse(string $body, int $statusCode = 200): ResponseInterface {
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('__toString')->willReturn($body);
    $stream->method('getContents')->willReturn($body);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn($statusCode);
    $response->method('getBody')->willReturn($stream);

    return $response;
  }

  /**
   * Builds an HtmlFetchService wired with the given client and optional request.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Guzzle client mock.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   Optional request to return from RequestStack::getCurrentRequest().
   */
  private function buildService(ClientInterface $httpClient, ?Request $request = NULL): HtmlFetchService {
    $requestStack = $this->createMock(RequestStack::class);
    $requestStack->method('getCurrentRequest')->willReturn($request);
    return new HtmlFetchService($httpClient, $requestStack);
  }

  /**
   * Creates a GuzzleException that can be thrown to simulate network failures.
   *
   * Returns an anonymous class that extends RuntimeException and implements
   * GuzzleException so it is caught by the service's catch clause.
   *
   * @param string $message
   *   Exception message.
   *
   * @return \GuzzleHttp\Exception\GuzzleException
   */
  private function buildGuzzleException(string $message = 'Network failure'): GuzzleException {
    return new class($message) extends \RuntimeException implements GuzzleException {};
  }

  // ---------------------------------------------------------------------------
  // getBaseUrl() tests
  // ---------------------------------------------------------------------------

  /**
   * GetBaseUrl() returns scheme + host from the current request.
   *
   * @covers ::getBaseUrl
   */
  public function testGetBaseUrlReturnsSchemeAndHttpHostFromRequest(): void {
    $request = $this->createMock(Request::class);
    $request->method('getSchemeAndHttpHost')->willReturn('https://example.com');

    $service = $this->buildService($this->createMock(ClientInterface::class), $request);

    $this->assertSame('https://example.com', $service->getBaseUrl());
  }

  /**
   * GetBaseUrl() falls back to 'http://localhost' when no request is available.
   *
   * @covers ::getBaseUrl
   */
  public function testGetBaseUrlFallsBackToLocalhostWhenNoRequest(): void {
    $service = $this->buildService($this->createMock(ClientInterface::class), NULL);

    $this->assertSame('http://localhost', $service->getBaseUrl());
  }

  // ---------------------------------------------------------------------------
  // fetchPageHtml() tests
  // ---------------------------------------------------------------------------

  /**
   * FetchPageHtml() returns the response body string on HTTP 200.
   *
   * @covers ::fetchPageHtml
   */
  public function testFetchPageHtmlReturnsBodyStringOnSuccess(): void {
    $html       = '<html><body>Hello World</body></html>';
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')->willReturn($this->buildResponse($html, 200));

    $service = $this->buildService($httpClient);

    $this->assertSame($html, $service->fetchPageHtml('https://example.com/'));
  }

  /**
   * FetchPageHtml() returns NULL when Guzzle throws an exception.
   *
   * @covers ::fetchPageHtml
   */
  public function testFetchPageHtmlReturnsNullOnGuzzleException(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')->willThrowException(
      $this->buildGuzzleException('cURL error 6: could not resolve host')
    );

    $service = $this->buildService($httpClient);

    $this->assertNull($service->fetchPageHtml('https://unreachable.example.com/'));
  }

  /**
   * FetchPageHtml() returns NULL when a generic exception is thrown.
   *
   * @covers ::fetchPageHtml
   */
  public function testFetchPageHtmlReturnsNullOnGenericException(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')->willThrowException(
      new \RuntimeException('Unexpected error')
    );

    $service = $this->buildService($httpClient);

    $this->assertNull($service->fetchPageHtml('https://example.com/error'));
  }

  /**
   * FetchPageHtml() uses in-memory cache — two calls for the same URL issue only
   * one HTTP request.
   *
   * @covers ::fetchPageHtml
   */
  public function testFetchPageHtmlCachesResultAndMakesOnlyOneHttpRequest(): void {
    $html       = '<html><body>Cached page</body></html>';
    $httpClient = $this->createMock(ClientInterface::class);

    // Expect exactly one HTTP request even though fetchPageHtml() is called twice.
    $httpClient->expects($this->once())
      ->method('request')
      ->willReturn($this->buildResponse($html));

    $service = $this->buildService($httpClient);

    $first  = $service->fetchPageHtml('https://example.com/page');
    $second = $service->fetchPageHtml('https://example.com/page');

    $this->assertSame($html, $first);
    $this->assertSame($html, $second);
  }

  /**
   * FetchPageHtml() caches a NULL result — a failed fetch is not retried.
   *
   * @covers ::fetchPageHtml
   */
  public function testFetchPageHtmlCachesNullOnFailureAndDoesNotRetry(): void {
    $httpClient = $this->createMock(ClientInterface::class);

    // Exception on the first call; a second HTTP request must never happen.
    $httpClient->expects($this->once())
      ->method('request')
      ->willThrowException($this->buildGuzzleException('Timeout'));

    $service = $this->buildService($httpClient);

    $first  = $service->fetchPageHtml('https://example.com/fail');
    $second = $service->fetchPageHtml('https://example.com/fail');

    $this->assertNull($first);
    $this->assertNull($second);
  }

  /**
   * Different URLs each trigger their own HTTP request (cache is URL-keyed).
   *
   * @covers ::fetchPageHtml
   */
  public function testFetchPageHtmlIssuesSeparateRequestsForDifferentUrls(): void {
    $httpClient = $this->createMock(ClientInterface::class);

    // Two distinct URLs → exactly two HTTP requests.
    $httpClient->expects($this->exactly(2))
      ->method('request')
      ->willReturn($this->buildResponse('<html><body></body></html>'));

    $service = $this->buildService($httpClient);

    $service->fetchPageHtml('https://example.com/page-a');
    $service->fetchPageHtml('https://example.com/page-b');
  }

  /**
   * ClearCache() causes the next fetchPageHtml() call to re-issue a request.
   *
   * @covers ::fetchPageHtml
   */
  public function testClearCacheForcesFreshRequestOnNextFetch(): void {
    $html       = '<html><body>Fresh</body></html>';
    $httpClient = $this->createMock(ClientInterface::class);

    // One request before clearCache() and one after → total 2.
    $httpClient->expects($this->exactly(2))
      ->method('request')
      ->willReturn($this->buildResponse($html));

    $service = $this->buildService($httpClient);

    $service->fetchPageHtml('https://example.com/page');
    $service->clearCache();
    $service->fetchPageHtml('https://example.com/page');
  }

}
