<?php

namespace Drupal\Tests\drupalbridge\Unit;

use PHPUnit\Framework\TestCase;
use Drupal\drupalbridge\HubSpotClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Psr\Log\LoggerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Comprehensive unit tests for HubSpotClient.
 *
 * @group drupalbridge
 * @coversDefaultClass \Drupal\drupalbridge\HubSpotClient
 */
class HubSpotClientTest extends TestCase {

  private function buildClient(array $responses): HubSpotClient {
    $mock         = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    $guzzle       = new Client(['handler' => $handlerStack]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('api_token')
      ->willReturn('test-token-123');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('drupalbridge.settings')
      ->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new HubSpotClient($guzzle, $configFactory, $loggerFactory);
  }

  /**
   * @covers ::get
   * Test 1 — GET 200 returns decoded array.
   */
  public function testGet200ReturnsDecodedArray(): void {
    $client = $this->buildClient([
      new Response(200, [], '{"results": [{"id": "1"}]}'),
    ]);

    $result = $client->get('/crm/v3/objects/contacts');

    $this->assertIsArray($result);
    $this->assertArrayHasKey('results', $result);
    $this->assertEquals('1', $result['results'][0]['id']);
  }

  /**
   * @covers ::post
   * Test 2 — POST 200 returns decoded array.
   */
  public function testPost200ReturnsDecodedArray(): void {
    $client = $this->buildClient([
      new Response(200, [], '{"id": "contact_123", "properties": {"email": "test@test.com"}}'),
    ]);

    $result = $client->post('/crm/v3/objects/contacts', [
      'properties' => ['email' => 'test@test.com'],
    ]);

    $this->assertEquals('contact_123', $result['id']);
    $this->assertEquals('test@test.com', $result['properties']['email']);
  }

  /**
   * @covers ::patch
   * Test 3 — PATCH 200 returns decoded array.
   */
  public function testPatch200ReturnsDecodedArray(): void {
    $client = $this->buildClient([
      new Response(200, [], '{"id": "contact_123", "properties": {"firstname": "Updated"}}'),
    ]);

    $result = $client->patch('/crm/v3/objects/contacts/contact_123', [
      'properties' => ['firstname' => 'Updated'],
    ]);

    $this->assertEquals('Updated', $result['properties']['firstname']);
  }

  /**
   * Test 4 — 400 Bad Request throws exception.
   */
  public function test400ThrowsException(): void {
    $this->expectException(RequestException::class);

    $client = $this->buildClient([
      new RequestException(
        'Bad Request',
        new Request('POST', '/crm/v3/objects/contacts'),
        new Response(400, [], '{"status":"error","message":"Invalid input"}')
      ),
    ]);

    $client->post('/crm/v3/objects/contacts', []);
  }

  /**
   * Test 5 — 401 Unauthorized throws exception.
   */
  public function test401ThrowsException(): void {
    $this->expectException(RequestException::class);

    $client = $this->buildClient([
      new RequestException(
        'Unauthorized',
        new Request('GET', '/crm/v3/objects/contacts'),
        new Response(401, [], '{"status":"error","message":"Authentication credentials not found"}')
      ),
    ]);

    $client->get('/crm/v3/objects/contacts');
  }

  /**
   * Test 6 — 403 Forbidden throws exception.
   */
  public function test403ThrowsException(): void {
    $this->expectException(RequestException::class);

    $client = $this->buildClient([
      new RequestException(
        'Forbidden',
        new Request('POST', '/crm/v3/objects/contacts'),
        new Response(403, [], '{"status":"error","message":"Missing required scope"}')
      ),
    ]);

    $client->post('/crm/v3/objects/contacts', []);
  }

  /**
   * Test 7 — 404 Not Found throws exception.
   */
  public function test404ThrowsException(): void {
    $this->expectException(RequestException::class);

    $client = $this->buildClient([
      new RequestException(
        'Not Found',
        new Request('GET', '/crm/v3/objects/contacts/999'),
        new Response(404, [], '{"status":"error","message":"Contact not found"}')
      ),
    ]);

    $client->get('/crm/v3/objects/contacts/999');
  }

  /**
   * Test 8 — 429 retries then succeeds on 200.
   */
  public function test429RetriesThenSucceeds(): void {
    $client = $this->buildClient([
      new RequestException(
        'Rate limit',
        new Request('POST', '/test'),
        new Response(429, [], 'Rate limit exceeded')
      ),
      new RequestException(
        'Rate limit',
        new Request('POST', '/test'),
        new Response(429, [], 'Rate limit exceeded')
      ),
      new Response(200, [], '{"status": "success"}'),
    ]);

    $result = $client->post('/test', []);
    $this->assertEquals('success', $result['status']);
  }

  /**
   * Test 9 — 429 exhausts all retries throws exception.
   */
  public function test429ExhaustingRetriesThrowsException(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/rate limit exceeded/i');

    $client = $this->buildClient([
      new RequestException('Rate limit', new Request('POST', '/test'), new Response(429, [], '')),
      new RequestException('Rate limit', new Request('POST', '/test'), new Response(429, [], '')),
      new RequestException('Rate limit', new Request('POST', '/test'), new Response(429, [], '')),
    ]);

    $client->post('/test', []);
  }

  /**
   * Test 10 — 500 Server Error throws exception.
   */
  public function test500ThrowsException(): void {
    $this->expectException(RequestException::class);

    $client = $this->buildClient([
      new RequestException(
        'Server Error',
        new Request('POST', '/crm/v3/objects/contacts'),
        new Response(500, [], '{"status":"error","message":"Internal server error"}')
      ),
    ]);

    $client->post('/crm/v3/objects/contacts', []);
  }

  /**
   * Test 11 — 503 Service Unavailable throws exception.
   */
  public function test503ThrowsException(): void {
    $this->expectException(RequestException::class);

    $client = $this->buildClient([
      new RequestException(
        'Service Unavailable',
        new Request('GET', '/crm/v3/objects/contacts'),
        new Response(503, [], 'Service temporarily unavailable')
      ),
    ]);

    $client->get('/crm/v3/objects/contacts');
  }

  /**
   * Test 12 — Empty response body returns empty array.
   */
  public function testEmptyResponseBodyReturnsEmptyArray(): void {
    $client = $this->buildClient([
      new Response(200, [], ''),
    ]);

    $result = $client->get('/crm/v3/objects/contacts');
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Test 13 — Authorization header is set correctly.
   */
  public function testAuthorizationHeaderSetCorrectly(): void {
    $mock         = new MockHandler([
      new Response(200, [], '{}'),
    ]);
    $container    = [];
    $history      = \GuzzleHttp\Middleware::history($container);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $guzzle = new Client(['handler' => $handlerStack]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('test-token-abc');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $client = new HubSpotClient($guzzle, $configFactory, $loggerFactory);
    $client->get('/test');

    $request = $container[0]['request'];
    $this->assertEquals(
      'Bearer test-token-abc',
      $request->getHeaderLine('Authorization')
    );
  }

  /**
   * Test 14 — getContactProperties returns results array.
   */
  public function testGetContactPropertiesReturnsResults(): void {
    $client = $this->buildClient([
      new Response(200, [], '{"results": [{"name": "email", "label": "Email"}]}'),
    ]);

    $properties = $client->getContactProperties();

    $this->assertIsArray($properties);
    $this->assertCount(1, $properties);
    $this->assertEquals('email', $properties[0]['name']);
  }

  /**
   * Test 15 — delete method works correctly.
   */
  public function testDeleteMethodWorksCorrectly(): void {
    $client = $this->buildClient([
      new Response(204, [], ''),
    ]);

    $result = $client->delete('/crm/v3/objects/contacts/123');
    $this->assertIsArray($result);
  }

}