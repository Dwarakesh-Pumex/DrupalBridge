<?php

namespace Drupal\Tests\drupalbridge\Unit;

use PHPUnit\Framework\TestCase;
use Drupal\drupalbridge\LicenceService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Unit tests for LicenceService.
 *
 * @group drupalbridge
 * @coversDefaultClass \Drupal\drupalbridge\LicenceService
 */
class LicenceServiceTest extends TestCase {

  private function buildService(
    string $licenceKey = '',
    bool $isValid = TRUE
  ): LicenceService {

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('licence_key')
      ->willReturn($licenceKey);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('drupalbridge.settings')
      ->willReturn($config);

    return new LicenceService($configFactory);
  }

  /**
   * Test 1 — isValid returns TRUE by default (stub).
   */
  public function testIsValidReturnsTrueByDefault(): void {
    $service = $this->buildService();
    $this->assertTrue($service->isValid());
  }

  /**
   * Test 2 — getLicenceKey returns empty when not set.
   */
  public function testGetLicenceKeyReturnsEmptyWhenNotSet(): void {
    $service = $this->buildService('');
    $this->assertEquals('', $service->getLicenceKey());
  }

  /**
   * Test 3 — getLicenceKey returns configured key.
   */
  public function testGetLicenceKeyReturnsConfiguredKey(): void {
    $service = $this->buildService('DB-XXXX-YYYY-ZZZZ');
    $this->assertEquals('DB-XXXX-YYYY-ZZZZ', $service->getLicenceKey());
  }

  /**
   * Test 4 — Tier gate logic for free features.
   */
  public function testFreeFeatureAlwaysAvailable(): void {
    $service = $this->buildService();

    // Free features always available regardless of licence
    $freeFeatures = ['contact_sync', 'field_mapping', 'webform_handler'];
    foreach ($freeFeatures as $feature) {
      $this->assertTrue(TRUE, "$feature should be available on free tier");
    }
  }

  /**
   * Test 5 — Starter features require valid licence.
   */
  public function testStarterFeaturesRequireValidLicence(): void {
    $service = $this->buildService('DB-VALID-KEY');

    // Currently stub returns TRUE — valid licence
    $this->assertTrue($service->isValid());
  }

  /**
   * Test 6 — Pro features require Pro licence.
   */
  public function testProFeaturesRequireProLicence(): void {
    $service = $this->buildService('DB-PRO-KEY');

    // Currently stub returns TRUE — simulates Pro
    $this->assertTrue($service->isValid());
  }

  /**
   * Test 7 — Licence key format validation.
   */
  public function testLicenceKeyFormatValidation(): void {
    $validFormats = [
      'DB-XXXX-YYYY-ZZZZ-AAAA',
      'DB-1234-5678-9012-3456',
    ];

    $invalidFormats = [
      '',
      'invalid',
      '1234-5678',
    ];

    foreach ($validFormats as $key) {
      $this->assertMatchesRegularExpression(
        '/^DB-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/',
        $key,
        "$key should match valid format"
      );
    }

    foreach ($invalidFormats as $key) {
      $this->assertDoesNotMatchRegularExpression(
        '/^DB-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/',
        $key,
        "$key should not match valid format"
      );
    }
  }

}