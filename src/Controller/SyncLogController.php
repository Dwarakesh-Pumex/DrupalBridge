<?php

namespace Drupal\drupalbridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Displays the DrupalBridge sync log.
 */
class SyncLogController extends ControllerBase {

  public function index(Request $request): array {
    $syncLogService = \Drupal::service('drupalbridge.sync_log_service');

    // Get filters from URL query params
    $filters = [
      'status'    => $request->query->get('status', ''),
      'email'     => $request->query->get('email', ''),
      'date_from' => $request->query->get('date_from', ''),
      'date_to'   => $request->query->get('date_to', ''),
    ];

    $entries    = $syncLogService->getEntries($filters);
    $totalCount = $syncLogService->getCount($filters);
    $failedCount = $syncLogService->getCount(['status' => 'failed']);

    // Build filter form
    $build['filters'] = [
      '#type'   => 'container',
      '#attributes' => ['style' => 'margin-bottom: 20px;'],
    ];

    $build['filters']['form'] = \Drupal::formBuilder()->getForm(
      '\Drupal\drupalbridge\Form\SyncLogFilterForm'
    );

    // Summary stats
    $build['stats'] = [
      '#type'   => 'markup',
      '#markup' => '
        <div style="margin-bottom: 20px; padding: 10px; background: #f5f5f5; border-radius: 4px;">
          <strong>Total entries:</strong> ' . $totalCount . ' &nbsp;|&nbsp;
          <strong style="color: red;">Failed:</strong> ' . $failedCount . ' &nbsp;|&nbsp;
          <a href="' . Url::fromRoute('drupalbridge.sync_log.bulk_retry')->toString() . '" class="button button--small">Retry All Failed</a>
          &nbsp;
          <a href="' . Url::fromRoute('drupalbridge.sync_log.export')->toString() . '" class="button button--small">Export CSV</a>
        </div>
      ',
    ];

    // Build table
    $rows = [];
    foreach ($entries as $entry) {
      $statusColor = match ($entry->status) {
        'synced'      => 'green',
        'failed'      => 'red',
        'queued'      => 'orange',
        'gdpr_deleted'=> 'gray',
        default       => 'gray',
      };

      $retryUrl = Url::fromRoute('drupalbridge.sync_log.retry', [
        'id' => $entry->id,
      ])->toString();

      $rows[] = [
        $entry->email,
        ['data' => ['#markup' => '<strong style="color:' . $statusColor . ';">' . strtoupper($entry->status) . '</strong>']],
        $entry->source,
        $entry->attempts,
        \Drupal::service('date.formatter')->format($entry->updated, 'short'),
        ['data' => ['#markup' => $entry->error_message
          ? '<span title="' . htmlspecialchars($entry->error_message) . '" style="color:red;cursor:pointer;">⚠ View error</span>'
          : '—'
        ]],
        ['data' => ['#markup' => $entry->status === 'failed'
          ? '<a href="' . $retryUrl . '" class="button button--small button--primary">Retry</a>'
          : '—'
        ]],
      ];
    }

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('Email'),
        $this->t('Status'),
        $this->t('Source'),
        $this->t('Attempts'),
        $this->t('Last Updated'),
        $this->t('Error'),
        $this->t('Actions'),
      ],
      '#rows'  => $rows,
      '#empty' => $this->t('No sync log entries found.'),
    ];

    return $build;
  }

  /**
   * Retry a single failed sync.
   */
  public function retry(int $id): \Symfony\Component\HttpFoundation\RedirectResponse {
  $database = \Drupal::database();

  $entry = $database->select('drupalbridge_sync_log', 'l')
    ->fields('l')
    ->condition('l.id', $id)
    ->execute()
    ->fetchObject();

  if (!$entry) {
    \Drupal::messenger()->addError('Log entry not found.');
    return $this->redirect('drupalbridge.sync_log');
  }

  try {
    $formSyncService = \Drupal::service('drupalbridge.form_sync');
    $formSyncService->createOrUpdateContact([
      'email' => $entry->email,
    ]);

    // Update status to synced in log
    $database->update('drupalbridge_sync_log')
      ->fields([
        'status'  => 'synced',
        'updated' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', $id)
      ->execute();

    \Drupal::messenger()->addStatus(
      'Successfully retried sync for ' . $entry->email
    );

  }
  catch (\Exception $e) {
    // Update status to failed — do NOT queue again from here
    $database->update('drupalbridge_sync_log')
      ->fields([
        'status'        => 'failed',
        'error_message' => $e->getMessage(),
        'attempts'      => $entry->attempts + 1,
        'updated'       => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', $id)
      ->execute();

    \Drupal::messenger()->addError(
      'Retry failed for ' . $entry->email . ': ' . $e->getMessage()
    );
  }

  return $this->redirect('drupalbridge.sync_log');
}

  /**
   * Bulk retry all failed syncs.
   */
  public function bulkRetry(): \Symfony\Component\HttpFoundation\RedirectResponse {

  $syncLogService = \Drupal::service('drupalbridge.sync_log_service');
  $failedEntries  = $syncLogService->getFailedEntries();

  $queue = \Drupal::queue('drupalbridge_sync_worker');

  $count = 0;

  foreach ($failedEntries as $entry) {

    if (empty($entry->raw_values)) {
      continue;
    }

    $queue->createItem([
      'raw_values' => json_decode($entry->raw_values, TRUE),
      'attempts' => 0,
      'source' => 'bulk_retry',
    ]);

    $count++;
  }

  \Drupal::messenger()->addStatus("Queued $count items for retry.");
  \Drupal::service('cron')->run();

  return $this->redirect('drupalbridge.sync_log');
}

  /**
   * Export sync log as CSV.
   */
  public function export(): StreamedResponse {
    $licenceService = \Drupal::service('drupalbridge.licence_service');

    if (!$licenceService->isValid()) {
      \Drupal::messenger()->addError('CSV export requires DrupalBridge Starter.');
      return $this->redirect('drupalbridge.sync_log');
    }

    $syncLogService = \Drupal::service('drupalbridge.sync_log_service');
    $entries        = $syncLogService->getAllForExport();

    $response = new StreamedResponse(function () use ($entries) {
      $handle = fopen('php://output', 'w');

      // CSV headers
      fputcsv($handle, [
        'Email',
        'Status',
        'Source',
        'Attempts',
        'HubSpot Contact ID',
        'Error Message',
        'Created',
        'Last Updated',
      ]);

      foreach ($entries as $entry) {
        fputcsv($handle, [
          $entry->email,
          $entry->status,
          $entry->source,
          $entry->attempts,
          $entry->hubspot_contact_id ?? '',
          $entry->error_message ?? '',
          date('Y-m-d H:i:s', $entry->created),
          date('Y-m-d H:i:s', $entry->updated),
        ]);
      }

      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="drupalbridge-sync-log-' . date('Y-m-d') . '.csv"'
    );

    return $response;
  }

}