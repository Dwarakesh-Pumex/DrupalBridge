<?php

namespace Drupal\drupalbridge\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\drupalbridge\FormSyncService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Queue\RequeueException;

/**
 * Processes failed HubSpot sync items from the queue.
 *
 * @QueueWorker(
 *   id = "drupalbridge_sync_worker",
 *   title = @Translation("DrupalBridge HubSpot Sync Worker"),
 *   cron = {"time" = 60}
 * )
 */
class DrupalBridgeSyncWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected FormSyncService $formSyncService;
  protected LoggerChannelFactoryInterface $loggerFactory;

  protected int $maxAttempts = 3;

  protected array $backoffSchedule = [
    1 => 300,   // 5 min
    2 => 900,   // 15 min
    3 => 2700,  // 45 min
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    FormSyncService $formSyncService,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formSyncService = $formSyncService;
    $this->loggerFactory   = $loggerFactory;
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('drupalbridge.form_sync'),
      $container->get('logger.factory')
    );
  }

  /**
   * Process a single queue item.
   */
  public function processItem($data): void {

    // ⏱ Delay retry if needed
    if (!empty($data['next_retry']) && $data['next_retry'] > time()) {
      throw new RequeueException('Not ready to retry yet.');
    }

    $attempts = $data['attempts'] ?? 0;

    // Get contact data
    if (!empty($data['contact_data'])) {
      $contactData = $data['contact_data'];
    }
    elseif (!empty($data['raw_values'])) {
      $contactData = $this->formSyncService->mapFormValues($data['raw_values']);
    }
    else {
      $this->loggerFactory->get('drupalbridge')->error('Queue item missing data.');
      return;
    }

    $email = $contactData['email'] ?? '';

    $this->loggerFactory->get('drupalbridge')->notice(
      'Queue processing: email=@email attempt=@attempt',
      [
        '@email' => $email ?: 'empty',
        '@attempt' => $attempts,
      ]
    );

    if (empty($email)) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Queue item has no email. Discarding.'
      );
      return;
    }

    try {
      // 🔥 MAIN SYNC
      $this->formSyncService->createOrUpdateContact($contactData);
      $this->loggerFactory->get('drupalbridge')->notice(
        'Queue success for @email after @attempt attempts',
        [
          '@email' => $email,
          '@attempt' => $attempts + 1,
        ]
      );
    }
    catch (\Exception $e) {

      $attempts++;

      // ❌ FINAL FAILURE
      if ($attempts >= $this->maxAttempts) {

        \Drupal::database()->update('drupalbridge_sync_log')
          ->fields([
            'error_message' => $e->getMessage(),
            'updated' => time(),
          ])
          ->condition('email', $email)
          ->condition('status', 'failed')
          ->execute();

        $this->loggerFactory->get('drupalbridge')->error(
          'Final failure after @max attempts for @email. Error: @error',
          [
            '@max' => $this->maxAttempts,
            '@email' => $email,
            '@error' => $e->getMessage(),
          ]
        );

        return;
      }

      // ⏳ Schedule retry
      $nextRun = time() + ($this->backoffSchedule[$attempts] ?? 2700);

      \Drupal::queue('drupalbridge_sync_worker')->createItem([
        'contact_data' => $contactData,
        'attempts'     => $attempts,   // ✅ FIXED (no reset)
        'next_retry'   => $nextRun,    // ✅ FIXED (delay)
        'source'       => 'retry',
      ]);

      $this->loggerFactory->get('drupalbridge')->warning(
        'Retry scheduled for @email in @minutes minutes (attempt @attempt)',
        [
          '@email' => $email,
          '@minutes' => ($this->backoffSchedule[$attempts] ?? 2700) / 60,
          '@attempt' => $attempts,
        ]
      );
    }
  }
}