<?php

namespace Drupal\drupalbridge\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin form to trigger historical sync.
 */
class HistoricalSyncForm extends FormBase {

  public function getFormId(): string {
    return 'drupalbridge_historical_sync_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $licenceService = \Drupal::service('drupalbridge.licence_service');

    // Tier gate
    if (!$licenceService->isValid()) {
      $form['upgrade'] = [
        '#type'   => 'markup',
        '#markup' => '
          <div class="messages messages--warning">
            <strong>🔒 Historical Sync requires DrupalBridge Starter.</strong>
            Upgrade to sync all existing users and contacts to HubSpot in bulk.
            <a href="https://drupalbridge.com/upgrade" target="_blank">Upgrade now →</a>
          </div>
        ',
      ];
      return $form;
    }

    $service    = \Drupal::service('drupalbridge.historical_sync_service');
    $totalUsers = $service->getTotalUsers();

    $rateLimitService = \Drupal::service('drupalbridge.rate_limit_service');
    $stats            = $rateLimitService->getStats();
    $avgTime    = $stats['avg_sync_time'];
    $errorCount = $stats['error_count'];
    $queueSize  = $stats['queue_size'];
    $targetMet  = $stats['target_met'];
    $autoQueued = $stats['auto_queued'];

    $form['performance'] = [
  '#type'   => 'markup',
  '#markup' => '
    <div style="margin-bottom: 20px; padding: 15px; background: #f0f0f0; border-radius: 4px;">
      <h3 style="margin-top: 0;">Performance Stats</h3>
      <table style="width: 100%; border-collapse: collapse;">
        <tr>
          <td style="padding: 5px;"><strong>Avg Sync Time:</strong></td>
          <td style="padding: 5px; color: ' . ($targetMet ? 'green' : 'red') . '">
            ' . $avgTime . 's
            ' . ($targetMet ? 'Target met' : 'Above 2s target') . '
          </td>
        </tr>
        <tr>
          <td style="padding: 5px;"><strong>Errors (last hour):</strong></td>
          <td style="padding: 5px; color: ' . ($errorCount >= 5 ? 'red' : 'green') . '">
            ' . $errorCount . '
            ' . ($errorCount >= 5 ? ' Alert threshold reached' : 'Normal') . '
          </td>
        </tr>
        <tr>
          <td style="padding: 5px;"><strong>Queue Size:</strong></td>
          <td style="padding: 5px; color: ' . ($queueSize >= 50 ? 'orange' : 'green') . '">
            ' . $queueSize . '
            ' . ($queueSize >= 50 ? 'Auto-queued mode active' : '') . '
          </td>
        </tr>
        <tr>
          <td style="padding: 5px;"><strong>Mode:</strong></td>
          <td style="padding: 5px;">
            ' . ($autoQueued ? 'Auto-switched to queued' : 'Normal') . '
          </td>
        </tr>
      </table>
    </div>
  ',
  ];

    // Last sync info
    $lastSync = \Drupal::state()->get('drupalbridge.historical_sync_last_run');
    $lastSyncText = $lastSync
      ? \Drupal::service('date.formatter')->format($lastSync, 'short')
      : 'Never';

    $lastSyncResults = \Drupal::state()
      ->get('drupalbridge.historical_sync_last_results', []);

    $form['info'] = [
      '#type'   => 'markup',
      '#markup' => '
        <div class="messages messages--info">
          <strong>Historical Sync</strong> will push all existing Drupal users
          to HubSpot as contacts in batches of 100.
          <br>Total users to sync: <strong>' . $totalUsers . '</strong>
          <br>Last sync run: <strong>' . $lastSyncText . '</strong>
          ' . (!empty($lastSyncResults) ? '
          <br>Last results —
          Success: <strong style="color:green">' . ($lastSyncResults['success'] ?? 0) . '</strong>
          Failed: <strong style="color:red">' . ($lastSyncResults['failed'] ?? 0) . '</strong>
          Skipped: <strong style="color:gray">' . ($lastSyncResults['skipped'] ?? 0) . '</strong>
          ' : '') . '
        </div>
      ',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Run Historical Sync'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    if ($totalUsers === 0) {
      $form['actions']['submit']['#disabled'] = TRUE;
      $form['actions']['submit']['#value']    = $this->t('No users to sync');
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $service    = \Drupal::service('drupalbridge.historical_sync_service');
    $totalUsers = $service->getTotalUsers();
    $batchSize  = \Drupal\drupalbridge\HistoricalSyncService::BATCH_SIZE;
    $totalBatches = ceil($totalUsers / $batchSize);

    // Build Drupal Batch API operations
    $operations = [];
    for ($i = 0; $i < $totalBatches; $i++) {
      $operations[] = [
        'drupalbridge_historical_sync_batch_process',
        [$i * $batchSize, $totalUsers],
      ];
    }

    $batch = [
      'title'            => $this->t('Syncing users to HubSpot...'),
      'operations'       => $operations,
      'finished'         => 'drupalbridge_historical_sync_batch_finished',
      'init_message'     => $this->t('Starting historical sync...'),
      'progress_message' => $this->t('Processing batch @current of @total...'),
      'error_message'    => $this->t('Historical sync encountered an error.'),
    ];

    batch_set($batch);
  }

}