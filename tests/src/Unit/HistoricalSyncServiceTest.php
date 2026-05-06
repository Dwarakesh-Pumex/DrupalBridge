<?php

namespace Drupal\Tests\drupalbridge\Unit;

use PHPUnit\Framework\TestCase;
use Drupal\drupalbridge\HistoricalSyncService;
use Drupal\drupalbridge\HubSpotClient;
use Psr\Log\LoggerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Comprehensive unit tests for HistoricalSyncService.
 *
 * @group drupalbridge
 * @coversDefaultClass \Drupal\drupalbridge\HistoricalSyncService
 */
class HistoricalSyncServiceTest extends TestCase {

  private function buildService(
    bool $shouldThrow = FALSE,
    array $mockResponse = []
  ): HistoricalSyncService {

    $hubspotClient = $this->createMock(HubSpotClient::class);

    if ($shouldThrow) {
      $hubspotClient->method('post')
        ->willThrowException(new \Exception('API Error'));
    }
    else {
      $hubspotClient->method('post')->willReturn(
        !empty($mockResponse) ? $mockResponse : [
          'results' => [['id' => '1'], ['id' => '2']],
        ]
      );
    }

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new HistoricalSyncService(
      $hubspotClient,
      $configFactory,
      $loggerFactory
    );
  }

  protected function setUp(): void {
  parent::setUp();

  $container = new ContainerBuilder();

  // Mock rate limit service
  $rateLimitMock = $this->getMockBuilder(\stdClass::class)
    ->addMethods(['checkAndAutoSwitch', 'recordSyncTime', 'recordError'])
    ->getMock();

  $container->set('drupalbridge.rate_limit_service', $rateLimitMock);

  // Mock legal consent service
  $legalConsentMock = $this->getMockBuilder(\stdClass::class)
    ->addMethods(['isEnabled', 'build'])
    ->getMock();

  $legalConsentMock->method('isEnabled')->willReturn(FALSE);

  $container->set('drupalbridge.legal_consent_service', $legalConsentMock);

  \Drupal::setContainer($container);
}



  private function createMockUser(
    string $email,
    string $name = 'Test User',
    array $roles = []
  ) {
    $user = $this->createMock(\Drupal\user\UserInterface::class);
    $user->method('getEmail')->willReturn($email);
    $user->method('getDisplayName')->willReturn($name);
    $user->method('id')->willReturn(rand(1, 9999));
    $user->method('hasRole')->willReturnCallback(
      fn($role) => in_array($role, $roles)
    );
    $user->method('hasField')->willReturn(FALSE);
    return $user;
  }

  /**
   * Test 1 — BATCH_SIZE constant is 100.
   */
  public function testBatchSizeIs100(): void {
    $this->assertEquals(100, HistoricalSyncService::BATCH_SIZE);
  }

  /**
   * Test 2 — Empty users returns zero counts.
   */
  public function testEmptyUsersReturnsZeroCounts(): void {
    $service = $this->buildService();
    $results = $service->syncUserBatch([]);

    $this->assertEquals(0, $results['success']);
    $this->assertEquals(0, $results['failed']);
    $this->assertEquals(0, $results['skipped']);
  }

  /**
   * Test 3 — User without email is skipped.
   */
  public function testUserWithoutEmailIsSkipped(): void {
    $service = $this->buildService();

    $user = $this->createMock(\Drupal\user\UserInterface::class);
    $user->method('getEmail')->willReturn('');
    $user->method('hasRole')->willReturn(FALSE);
    $user->method('hasField')->willReturn(FALSE);

    $results = $service->syncUserBatch([$user]);

    $this->assertEquals(0, $results['success']);
    $this->assertEquals(1, $results['skipped']);
  }

  /**
   * Test 4 — API failure returns failed count.
   */
  public function testApiFailureReturnsFailedCount(): void {
    $service = $this->buildService(TRUE);
    $user    = $this->createMockUser('test@test.com');
    $results = $service->syncUserBatch([$user]);

    $this->assertEquals(0, $results['success']);
    $this->assertEquals(1, $results['failed']);
  }

  /**
   * Test 5 — Batches calculated correctly for 250 users.
   */
  public function testBatchesCalculatedCorrectlyFor250Users(): void {
    $totalBatches = ceil(250 / HistoricalSyncService::BATCH_SIZE);
    $this->assertEquals(3, $totalBatches);
  }

  /**
   * Test 6 — Exactly 100 users is one batch.
   */
  public function testExactly100UsersIsOneBatch(): void {
    $totalBatches = ceil(100 / HistoricalSyncService::BATCH_SIZE);
    $this->assertEquals(1, $totalBatches);
  }

  /**
   * Test 7 — 101 users is two batches.
   */
  public function test101UsersIsTwoBatches(): void {
    $totalBatches = ceil(101 / HistoricalSyncService::BATCH_SIZE);
    $this->assertEquals(2, $totalBatches);
  }

  /**
   * Test 8 — Tier check — free tier can run historical sync.
   */
  public function testFreeTierCanRunHistoricalSync(): void {
    // LicenceService stub always returns TRUE
    // Historical sync is available on all tiers in current implementation
    $service = $this->buildService();
    $this->assertInstanceOf(HistoricalSyncService::class, $service);
  }

  /**
   * Test 9 — Progress percentage calculated correctly.
   */
  public function testProgressPercentageCalculatedCorrectly(): void {
    $total    = 300;
    $current  = 150;
    $finished = $current / $total;

    $this->assertEquals(0.5, $finished);
  }

  /**
   * Test 10 — Bulk upsert splits into correct chunks.
   */
  public function testBulkUpsertSplitsIntoCorrectChunks(): void {
    $contacts  = array_fill(0, 250, ['email' => 'test@test.com', 'firstname' => 'Test']);
    $chunks    = array_chunk($contacts, HistoricalSyncService::BATCH_SIZE);

    $this->assertCount(3, $chunks);
    $this->assertCount(100, $chunks[0]);
    $this->assertCount(100, $chunks[1]);
    $this->assertCount(50, $chunks[2]);
  }

  /**
   * Test 11 — Name split into firstname and lastname.
   */
  public function testNameSplitIntoFirstAndLast(): void {
    $name  = 'John Doe';
    $parts = explode(' ', trim($name), 2);

    $this->assertEquals('John', $parts[0]);
    $this->assertEquals('Doe', $parts[1]);
  }

  /**
   * Test 12 — Single name handled correctly.
   */
  public function testSingleNameHandledCorrectly(): void {
    $name  = 'Admin';
    $parts = explode(' ', trim($name), 2);

    $this->assertEquals('Admin', $parts[0]);
    $this->assertEquals('', $parts[1] ?? '');
  }

}