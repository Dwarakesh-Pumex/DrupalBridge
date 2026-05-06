<?php

namespace Drupal\drupalbridge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Handles rate limit protection and bulk sync management.
 */
class RateLimitService {

  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;

  // HubSpot rate limits
  const MAX_REQUESTS_PER_SECOND  = 10;
  const MAX_REQUESTS_PER_DAY     = 250000;
  const BULK_THRESHOLD           = 50;
  const BATCH_SIZE               = 100;
  const ERROR_ALERT_THRESHOLD    = 5;
  const ERROR_ALERT_WINDOW       = 3600; // 1 hour in seconds
  const TARGET_SYNC_TIME_SECONDS = 2;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->configFactory = $configFactory;
    $this->loggerFactory = $loggerFactory;
  }

  // -------------------------------------------------------------------------
  // AUTO QUEUE SWITCHING
  // -------------------------------------------------------------------------

  /**
   * Check if pending queue exceeds bulk threshold.
   * If yes auto-switch to queued mode.
   */
  public function checkAndAutoSwitch(): bool {
    $queue        = \Drupal::queue('drupalbridge_sync_worker');
    $pendingCount = $queue->numberOfItems();

    if ($pendingCount >= self::BULK_THRESHOLD) {
      $currentMode = $this->configFactory
        ->get('drupalbridge.settings')
        ->get('sync_mode');

      if ($currentMode !== 'queued') {
        \Drupal::configFactory()
          ->getEditable('drupalbridge.settings')
          ->set('sync_mode', 'queued')
          ->save();

        $this->loggerFactory->get('drupalbridge')->warning(
          'Auto-switched to queued mode. Pending items: @count (threshold: @threshold)',
          [
            '@count'     => $pendingCount,
            '@threshold' => self::BULK_THRESHOLD,
          ]
        );

        // Store auto-switch state
        \Drupal::state()->set(
          'drupalbridge.auto_switched_to_queued',
          TRUE
        );
        \Drupal::state()->set(
          'drupalbridge.auto_switch_timestamp',
          time()
        );

        return TRUE;
      }
    }
    else {
      // Restore realtime if queue drops below threshold
      $autoSwitched = \Drupal::state()
        ->get('drupalbridge.auto_switched_to_queued', FALSE);

      if ($autoSwitched) {
        \Drupal::configFactory()
          ->getEditable('drupalbridge.settings')
          ->set('sync_mode', 'realtime')
          ->save();

        \Drupal::state()->set(
          'drupalbridge.auto_switched_to_queued',
          FALSE
        );

        $this->loggerFactory->get('drupalbridge')->notice(
          'Auto-restored realtime mode. Queue cleared below threshold.'
        );
      }
    }

    return FALSE;
  }

  // -------------------------------------------------------------------------
  // PERFORMANCE TRACKING
  // -------------------------------------------------------------------------

  /**
   * Record sync time for performance monitoring.
   */
  public function recordSyncTime(float $seconds, string $email): void {
    $key   = 'drupalbridge.sync_times';
    $times = \Drupal::state()->get($key, []);

    $times[] = [
      'time'      => $seconds,
      'email'     => $email,
      'timestamp' => time(),
    ];

    // Keep only last 100 entries
    if (count($times) > 100) {
      $times = array_slice($times, -100);
    }

    \Drupal::state()->set($key, $times);

    if ($seconds > self::TARGET_SYNC_TIME_SECONDS) {
      $this->loggerFactory->get('drupalbridge')->warning(
        'Slow sync detected for @email: @time seconds (target: @target)',
        [
          '@email'  => $email,
          '@time'   => round($seconds, 2),
          '@target' => self::TARGET_SYNC_TIME_SECONDS,
        ]
      );
    }
  }

  /**
   * Get average sync time.
   */
  public function getAverageSyncTime(): float {
    $times = \Drupal::state()->get('drupalbridge.sync_times', []);

    if (empty($times)) {
      return 0.0;
    }

    $sum = array_sum(array_column($times, 'time'));
    return $sum / count($times);
  }

  // -------------------------------------------------------------------------
  // ERROR RATE ALERTING
  // -------------------------------------------------------------------------

  /**
   * Record an error and check if alert threshold is exceeded.
   */
  public function recordError(string $errorMessage, string $email = ''): void {
    $key    = 'drupalbridge.error_log';
    $errors = \Drupal::state()->get($key, []);
    $now    = time();

    $errors[] = [
      'message'   => $errorMessage,
      'email'     => $email,
      'timestamp' => $now,
    ];

    // Keep only errors within the alert window
    $errors = array_filter($errors, function ($error) use ($now) {
      return ($now - $error['timestamp']) <= self::ERROR_ALERT_WINDOW;
    });

    \Drupal::state()->set($key, array_values($errors));

    $errorCount = count($errors);

    $this->loggerFactory->get('drupalbridge')->notice(
      'Error recorded. Total errors in last hour: @count',
      ['@count' => $errorCount]
    );

    // Check if alert threshold exceeded
    if ($errorCount >= self::ERROR_ALERT_THRESHOLD) {
      $this->sendErrorAlert($errorCount, $errors);
    }
  }

  /**
   * Send error rate alert email.
   */
  private function sendErrorAlert(int $errorCount, array $errors): void {
    // Check if alert already sent recently (prevent duplicate alerts)
    $lastAlert = \Drupal::state()
      ->get('drupalbridge.last_error_alert', 0);

    // Only send one alert per hour
    if ((time() - $lastAlert) < self::ERROR_ALERT_WINDOW) {
      return;
    }

    \Drupal::state()->set('drupalbridge.last_error_alert', time());

    $siteEmail  = \Drupal::config('system.site')->get('mail');
    $siteName   = \Drupal::config('system.site')->get('name');

    $recentErrors = array_slice($errors, -5);
    $errorList    = implode("\n", array_map(
      fn($e) => '- ' . $e['email'] . ': ' . $e['message'],
      $recentErrors
    ));

    $mailManager = \Drupal::service('plugin.manager.mail');
    $mailManager->mail(
      'drupalbridge',
      'error_alert',
      $siteEmail,
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      [
        'error_count' => $errorCount,
        'error_list'  => $errorList,
        'site_name'   => $siteName,
        'threshold'   => self::ERROR_ALERT_THRESHOLD,
        'window'      => self::ERROR_ALERT_WINDOW / 60,
      ]
    );

    $this->loggerFactory->get('drupalbridge')->warning(
      'Error rate alert sent. @count errors in last @window minutes.',
      [
        '@count'  => $errorCount,
        '@window' => self::ERROR_ALERT_WINDOW / 60,
      ]
    );
  }

  /**
   * Get current error count in alert window.
   */
  public function getErrorCount(): int {
    $errors = \Drupal::state()->get('drupalbridge.error_log', []);
    $now    = time();

    return count(array_filter($errors, function ($error) use ($now) {
      return ($now - $error['timestamp']) <= self::ERROR_ALERT_WINDOW;
    }));
  }

  /**
   * Get performance stats summary.
   */
  public function getStats(): array {
    return [
      'avg_sync_time'   => round($this->getAverageSyncTime(), 3),
      'error_count'     => $this->getErrorCount(),
      'queue_size'      => \Drupal::queue('drupalbridge_sync_worker')->numberOfItems(),
      'auto_queued'     => \Drupal::state()->get('drupalbridge.auto_switched_to_queued', FALSE),
      'target_met'      => $this->getAverageSyncTime() <= self::TARGET_SYNC_TIME_SECONDS,
    ];
  }

}