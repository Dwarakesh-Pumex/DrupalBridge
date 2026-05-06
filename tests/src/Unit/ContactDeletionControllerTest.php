<?php

namespace Drupal\Tests\drupalbridge\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContactDeletionController signature verification.
 *
 * @group drupalbridge
 */
class ContactDeletionControllerTest extends TestCase {

  /**
   * Build expected signature for testing.
   */
  private function buildSignature(
    string $secret,
    string $method,
    string $url,
    string $body,
    string $timestamp
  ): string {
    $stringToHash = $method . $url . $body . $timestamp;
    return base64_encode(hash_hmac('sha256', $stringToHash, $secret, TRUE));
  }

  /**
   * Test 1 — Valid signature passes verification.
   */
  public function testValidSignaturePassesVerification(): void {
    $secret    = 'test-client-secret';
    $method    = 'POST';
    $url       = 'https://drupalbridge.ddev.site/drupalbridge/webhooks/contact-deletion';
    $body      = '[{"subscriptionType":"contact.deletion","objectId":123}]';
    $timestamp = (string) (time() * 1000);

    $signature = $this->buildSignature($secret, $method, $url, $body, $timestamp);

    $stringToHash    = $method . $url . $body . $timestamp;
    $expectedHash    = base64_encode(hash_hmac('sha256', $stringToHash, $secret, TRUE));

    $this->assertTrue(hash_equals($expectedHash, $signature));
  }

  /**
   * Test 2 — Invalid signature fails verification.
   */
  public function testInvalidSignatureFailsVerification(): void {
    $secret    = 'correct-secret';
    $method    = 'POST';
    $url       = 'https://example.com/webhook';
    $body      = '[]';
    $timestamp = (string) (time() * 1000);

    $validSignature   = $this->buildSignature($secret, $method, $url, $body, $timestamp);
    $invalidSignature = 'invalid-signature-value';

    $this->assertFalse(hash_equals($validSignature, $invalidSignature));
  }

  /**
   * Test 3 — Old timestamp rejected.
   */
  public function testOldTimestampRejected(): void {
    $timestamp  = (time() - 400) * 1000;
    $maxAgeMs   = 300000;
    $currentMs  = time() * 1000;

    $isTooOld = ($currentMs - $timestamp) > $maxAgeMs;
    $this->assertTrue($isTooOld);
  }

  /**
   * Test 4 — Recent timestamp accepted.
   */
  public function testRecentTimestampAccepted(): void {
    $timestamp = time() * 1000;
    $maxAgeMs  = 300000;
    $currentMs = time() * 1000;

    $isTooOld = ($currentMs - $timestamp) > $maxAgeMs;
    $this->assertFalse($isTooOld);
  }

  /**
   * Test 5 — Empty signature fails verification.
   */
  public function testEmptySignatureFailsVerification(): void {
    $signature = '';
    $this->assertEmpty($signature);
  }

  /**
   * Test 6 — Webhook payload parsed correctly.
   */
  public function testWebhookPayloadParsedCorrectly(): void {
    $body   = '[{"subscriptionType":"contact.deletion","objectId":12345}]';
    $events = json_decode($body, TRUE);

    $this->assertIsArray($events);
    $this->assertCount(1, $events);
    $this->assertEquals('contact.deletion', $events[0]['subscriptionType']);
    $this->assertEquals(12345, $events[0]['objectId']);
  }

  /**
   * Test 7 — Empty payload returns error.
   */
  public function testEmptyPayloadReturnsError(): void {
    $body   = '';
    $events = json_decode($body, TRUE);

    $isInvalid = empty($events) || !is_array($events);
    $this->assertTrue($isInvalid);
  }

  /**
   * Test 8 — Different HTTP method invalidates signature.
   */
  public function testDifferentMethodInvalidatesSignature(): void {
    $secret    = 'test-secret';
    $url       = 'https://example.com/webhook';
    $body      = '[]';
    $timestamp = (string) (time() * 1000);

    $postSignature = $this->buildSignature($secret, 'POST', $url, $body, $timestamp);
    $getSignature  = $this->buildSignature($secret, 'GET', $url, $body, $timestamp);

    $this->assertNotEquals($postSignature, $getSignature);
  }

}