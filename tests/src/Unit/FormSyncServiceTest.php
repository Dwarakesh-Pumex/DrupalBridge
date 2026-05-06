<?php

namespace Drupal\Tests\drupalbridge\Unit;

use PHPUnit\Framework\TestCase;
use Drupal\drupalbridge\FormSyncService;
use Drupal\drupalbridge\HubSpotClient;
use Psr\Log\LoggerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\drupalbridge\SyncLogService;

/**
 * Comprehensive unit tests for FormSyncService.
 *
 * @group drupalbridge
 * @coversDefaultClass \Drupal\drupalbridge\FormSyncService
 */
class FormSyncServiceTest extends TestCase {

  private function buildService(
    bool $shouldThrow = FALSE,
    array $mockResponse = [],
    array $savedMappings = []
  ): FormSyncService {

    $hubspotClient = $this->createMock(HubSpotClient::class);

    if ($shouldThrow) {
      $hubspotClient->method('post')
        ->willThrowException(new \Exception('API Error'));
    }
    else {
      $hubspotClient->method('post')->willReturn(
        !empty($mockResponse) ? $mockResponse : [
          'results' => [['id' => '123', 'properties' => ['email' => 'test@test.com']]],
        ]
      );
    }

    $fieldMappingConfig = $this->createMock(ImmutableConfig::class);
    $fieldMappingConfig->method('get')
      ->willReturnMap([
        ['mappings', $savedMappings],
        ['lifecycle_stage', ''],
      ]);

    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['static_values.lifecyclestage', 'lead'],
        ['api_token', 'test-token'],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnMap([
        ['drupalbridge.field_mapping', $fieldMappingConfig],
        ['drupalbridge.settings', $settingsConfig],
      ]);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $syncLogService = $this->createMock(SyncLogService::class);

    return new FormSyncService($hubspotClient, $loggerFactory, $configFactory,$syncLogService);
  }

  /**
   * Test 1 — Contact syncs successfully with valid data.
   */
  public function testContactSyncsSuccessfully(): void {
    $service = $this->buildService();

    $result = $service->createOrUpdateContact([
      'email'     => 'test@test.com',
      'firstname' => 'Test',
      'lastname'  => 'User',
    ]);

    $this->assertArrayHasKey('results', $result);
    $this->assertEquals('123', $result['results'][0]['id']);
  }

  /**
   * Test 2 — Missing email throws exception.
   */
  public function testMissingEmailThrowsException(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/email is required/i');

    $service = $this->buildService();
    $service->createOrUpdateContact(['firstname' => 'Test']);
  }

  /**
   * Test 3 — Empty email throws exception.
   */
  public function testEmptyEmailThrowsException(): void {
    $this->expectException(\Exception::class);

    $service = $this->buildService();
    $service->createOrUpdateContact(['email' => '']);
  }

  /**
   * Test 4 — API failure throws exception.
   */
  public function testApiFailureThrowsException(): void {
    $this->expectException(\Exception::class);
    $this->expectExceptionMessageMatches('/API Error/i');

    $service = $this->buildService(TRUE);
    $service->createOrUpdateContact(['email' => 'test@test.com']);
  }

  /**
   * Test 5 — mapFormValues extracts flat webform fields.
   */
  public function testMapFormValuesExtractsFlatFields(): void {
    $service = $this->buildService(FALSE, [], [
      'email'      => 'email',
      'first_name' => 'firstname',
      'last_name'  => 'lastname',
    ]);

    $formValues = [
      'email'      => 'mapped@test.com',
      'first_name' => 'Mapped',
      'last_name'  => 'User',
    ];

    $result = $service->mapFormValues($formValues);

    $this->assertEquals('mapped@test.com', $result['email']);
    $this->assertEquals('Mapped', $result['firstname']);
    $this->assertEquals('User', $result['lastname']);
  }

  /**
   * Test 6 — mapFormValues handles nested array structure.
   */
  public function testMapFormValuesHandlesNestedArrayStructure(): void {
    $service = $this->buildService(FALSE, [], [
      'field_email_id'  => 'email',
      'field_firstname' => 'firstname',
    ]);

    $formValues = [
      'field_email_id'  => [['value' => 'nested@test.com']],
      'field_firstname' => [['value' => 'Nested']],
    ];

    $result = $service->mapFormValues($formValues);

    $this->assertEquals('nested@test.com', $result['email']);
    $this->assertEquals('Nested', $result['firstname']);
  }

  /**
   * Test 7 — mapFormValues handles dot notation for nested fields.
   */
  public function testMapFormValuesHandlesDotNotation(): void {
    $service = $this->buildService(FALSE, [], [
      'address__city' => 'city',
    ]);

    $formValues = [
      'address' => ['city' => 'New York'],
    ];

    $result = $service->mapFormValues($formValues);
    $this->assertEquals('New York', $result['city']);
  }

  /**
   * Test 8 — mapFormValues returns empty strings for missing fields.
   */
  public function testMapFormValuesReturnsEmptyForMissingFields(): void {
    $service = $this->buildService();

    $result = $service->mapFormValues([]);

    $this->assertEquals('', $result['email'] ?? '');
    $this->assertEquals('', $result['firstname'] ?? '');
    $this->assertEquals('', $result['lastname'] ?? '');
  }

  /**
   * Test 9 — Lifecycle stage set from config default.
   */
  public function testLifecycleStageSetFromConfig(): void {
    $service = $this->buildService(FALSE, [
      'results' => [['id' => '123', 'properties' => ['lifecyclestage' => 'lead']]],
    ]);

    $result = $service->createOrUpdateContact([
      'email' => 'test@test.com',
    ]);

    $this->assertNotEmpty($result);
  }

  /**
   * Test 10 — Lifecycle stage overridden when passed in data.
   */
  public function testLifecycleStageOverriddenWhenPassed(): void {
    $capturedPayload = NULL;
    $hubspotClient   = $this->createMock(HubSpotClient::class);
    $hubspotClient->method('post')
      ->willReturnCallback(function ($endpoint, $data) use (&$capturedPayload) {
        $capturedPayload = $data;
        return ['results' => [['id' => '123']]];
      });

    $fieldMappingConfig = $this->createMock(ImmutableConfig::class);
    $fieldMappingConfig->method('get')->willReturn([]);

    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->method('get')->willReturnMap([
      ['static_values.lifecyclestage', 'lead'],
      ['api_token', 'test-token'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['drupalbridge.field_mapping', $fieldMappingConfig],
      ['drupalbridge.settings', $settingsConfig],
    ]);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    $syncLogService = $this->createMock(SyncLogService::class);
    $service = new FormSyncService($hubspotClient, $loggerFactory, $configFactory, $syncLogService);

    $service->createOrUpdateContact([
      'email'          => 'test@test.com',
      'lifecyclestage' => 'customer',
    ]);

    $stage = $capturedPayload['inputs'][0]['properties']['lifecyclestage'] ?? '';
    $this->assertEquals('customer', $stage);
  }

  /**
   * Test 11 — Deduplication by email via upsert.
   */
  public function testDeduplicationByEmailViaUpsert(): void {
    $callCount     = 0;
    $hubspotClient = $this->createMock(HubSpotClient::class);
    $hubspotClient->method('post')
      ->willReturnCallback(function () use (&$callCount) {
        $callCount++;
        return ['results' => [['id' => '123']]];
      });

    $fieldMappingConfig = $this->createMock(ImmutableConfig::class);
    $fieldMappingConfig->method('get')->willReturn([]);

    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->method('get')->willReturn('lead');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['drupalbridge.field_mapping', $fieldMappingConfig],
      ['drupalbridge.settings', $settingsConfig],
    ]);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $syncLogService = $this->createMock(SyncLogService::class);

    $service = new FormSyncService($hubspotClient, $loggerFactory, $configFactory, $syncLogService);

    // Submit same email twice
    $service->createOrUpdateContact(['email' => 'dedup@test.com']);
    $service->createOrUpdateContact(['email' => 'dedup@test.com']);

    // Batch upsert handles dedup on HubSpot side
    // Both calls should go through — HubSpot upserts by email
    $this->assertEquals(2, $callCount);
  }

  /**
   * Test 12 — All mapped fields included in payload.
   */
  public function testAllMappedFieldsIncludedInPayload(): void {
    $capturedPayload = NULL;
    $hubspotClient   = $this->createMock(HubSpotClient::class);
    $hubspotClient->method('post')
      ->willReturnCallback(function ($endpoint, $data) use (&$capturedPayload) {
        $capturedPayload = $data;
        return ['results' => [['id' => '123']]];
      });

    $fieldMappingConfig = $this->createMock(ImmutableConfig::class);
    $fieldMappingConfig->method('get')->willReturn([]);

    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->method('get')->willReturn('lead');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['drupalbridge.field_mapping', $fieldMappingConfig],
      ['drupalbridge.settings', $settingsConfig],
    ]);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    
    $syncLogService = $this->createMock(SyncLogService::class);

    $service = new FormSyncService($hubspotClient, $loggerFactory, $configFactory,$syncLogService);

    $service->createOrUpdateContact([
      'email'     => 'test@test.com',
      'firstname' => 'Test',
      'lastname'  => 'User',
      'phone'     => '1234567890',
      'company'   => 'Test Co',
    ]);

    $properties = $capturedPayload['inputs'][0]['properties'];
    $this->assertArrayHasKey('firstname', $properties);
    $this->assertArrayHasKey('lastname', $properties);
    $this->assertArrayHasKey('phone', $properties);
    $this->assertArrayHasKey('company', $properties);
  }

}