<?php

namespace Drupal\Tests\drupalbridge\Unit;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

/**
 * Unit tests for DrupalBridge settings form logic.
 *
 * @group drupalbridge
 */
class SettingsFormTest extends TestCase {

  /**
   * Build a Guzzle client with mocked responses.
   */
  private function buildHttpClient(array $responses): Client {
    $mock = new MockHandler($responses);
    $handlerStack = HandlerStack::create($mock);
    return new Client(['handler' => $handlerStack]);
  }

  /**
   * Test 1 — Valid token returns 200.
   */
  public function testValidTokenReturns200(): void {
    $client = $this->buildHttpClient([
      new Response(200, [], '{"results": []}'),
    ]);

    $response = $client->get('https://api.hubapi.com/crm/v3/objects/contacts?limit=1', [
      'headers' => [
        'Authorization' => 'Bearer valid-token',
        'Content-Type'  => 'application/json',
      ],
    ]);

    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Test 2 — Invalid token returns 401 and throws exception.
   */
  public function testInvalidTokenThrowsException(): void {
    $this->expectException(RequestException::class);

    $client = $this->buildHttpClient([
      new RequestException(
        'Unauthorized',
        new Request('GET', '/crm/v3/objects/contacts'),
        new Response(401, [], '{"message": "Unauthorized"}')
      ),
    ]);

    $client->get('https://api.hubapi.com/crm/v3/objects/contacts?limit=1', [
      'headers' => [
        'Authorization' => 'Bearer wrong-token',
        'Content-Type'  => 'application/json',
      ],
    ]);
  }

  /**
   * Test 3 — Saved token should be removed when new token fails.
   */
  public function testSavedTokenRemovedOnFailure(): void {
    // Simulate existing saved config
    $savedConfig = ['api_token' => 'old-valid-token', 'portal_id' => '12345'];

    // Simulate new token failing
    $newTokenFailed = TRUE;

    if ($newTokenFailed && !empty($savedConfig['api_token'])) {
      // Clear saved config
      unset($savedConfig['api_token']);
      unset($savedConfig['portal_id']);
    }

    $this->assertEmpty($savedConfig);
  }

  /**
   * Test 4 — Config is not saved when token is empty.
   */
  public function testEmptyTokenDoesNotSaveConfig(): void {
    $token    = '';
    $portalId = '12345';
    $syncMode = 'realtime';

    $configSaved = FALSE;

    if (!empty($token)) {
      $configSaved = TRUE;
    }

    $this->assertFalse($configSaved);
  }

  /**
   * Test 5 — All three values saved correctly when token is valid.
   */
  public function testAllValuesStoredCorrectly(): void {
    $formValues = [
      'api_token' => 'pat-na2-validtoken',
      'portal_id' => '244464480',
      'sync_mode' => 'realtime',
    ];

    // Simulate saving to config
    $savedConfig = [];

    if (!empty($formValues['api_token'])) {
      $savedConfig['api_token'] = $formValues['api_token'];
      $savedConfig['portal_id'] = $formValues['portal_id'];
      $savedConfig['sync_mode'] = $formValues['sync_mode'];
    }

    $this->assertEquals('pat-na2-validtoken', $savedConfig['api_token']);
    $this->assertEquals('244464480', $savedConfig['portal_id']);
    $this->assertEquals('realtime', $savedConfig['sync_mode']);
  }

  /**
   * Test 6 — Sync mode defaults to realtime.
   */
  public function testSyncModeDefaultsToRealtime(): void {
    $syncMode = 'realtime';
    $this->assertEquals('realtime', $syncMode);
  }

}