<?php

namespace Drupal\drupalbridge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Handles historical bulk sync of Drupal users to HubSpot.
 */
class HistoricalSyncService {

  protected HubSpotClient $hubspotClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelFactoryInterface $loggerFactory;

  // HubSpot batch upsert limit
  const BATCH_SIZE = 100;

  public function __construct(
    HubSpotClient $hubspotClient,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->hubspotClient = $hubspotClient;
    $this->configFactory = $configFactory;
    $this->loggerFactory = $loggerFactory;
  }

  /**
   * Get total count of users to sync.
   */
  public function getTotalUsers(): int {
    return (int) \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Get a batch of users starting from offset.
   */
  public function getUserBatch(int $offset): array {
    $uids = \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->condition('uid', 0, '>')
      ->accessCheck(FALSE)
      ->range($offset, self::BATCH_SIZE)
      ->execute();

    return \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadMultiple($uids);
  }

  /**
   * Sync a batch of users to HubSpot using batch upsert.
   *
   * @param array $users
   *   Array of user entities.
   *
   * @return array
   *   Results with success and failure counts.
   */
  public function syncUserBatch(array $users): array {
    $inputs          = [];
    $skipped         = 0;
    $excludedRoles = $this->configFactory
      ->get('drupalbridge.settings')
      ->get('user_sync_exclude_roles') ?? [];

    foreach ($users as $user) {
      $email = $user->getEmail();

      if (empty($email)) {
        $skipped++;
        continue;
      }

      // Check excluded roles
      $shouldSkip = FALSE;
      foreach ($excludedRoles as $role) {
        if ($user->hasRole($role)) {
          $shouldSkip = TRUE;
          break;
        }
      }

      if ($shouldSkip) {
        $skipped++;
        continue;
      }

      // Build properties
      $name  = $user->getDisplayName();
      $parts = explode(' ', trim($name), 2);

      $properties = [
        'email'     => $email,
        'firstname' => $parts[0] ?? '',
        'lastname'  => $parts[1] ?? '',
      ];

      // Apply user field mappings
      $userMappings = $this->configFactory
        ->get('drupalbridge.field_mapping')
        ->get('user_mappings') ?? [];

      foreach ($userMappings as $drupalField => $hubspotProperty) {
        if ($drupalField === 'name') {
          $properties[$hubspotProperty] = $user->getDisplayName();
        }
        elseif ($user->hasField($drupalField)) {
          $value = $user->get($drupalField)->value ?? '';
          if (!empty($value)) {
            $properties[$hubspotProperty] = $value;
          }
        }
      }

      $inputs[] = [
        'idProperty' => 'email',
        'id'         => $email,
        'properties' => $properties,
      ];
    }

    if (empty($inputs)) {
      return [
        'success' => 0,
        'failed'  => 0,
        'skipped' => $skipped,
      ];
    }

    try {
      $response = $this->hubspotClient->post(
        '/crm/v3/objects/contacts/batch/upsert',
        ['inputs' => $inputs]
      );

      $successCount = count($response['results'] ?? $inputs);

      // Store sync status for each user
      foreach ($users as $user) {
        \Drupal::service('user.data')->set(
          'drupalbridge',
          $user->id(),
          'hubspot_sync_status',
          'synced'
        );
        \Drupal::service('user.data')->set(
          'drupalbridge',
          $user->id(),
          'hubspot_last_sync',
          \Drupal::time()->getRequestTime()
        );
      }

      $this->loggerFactory->get('drupalbridge')->notice(
        'Historical sync batch completed. Success: @success Skipped: @skipped',
        [
          '@success' => $successCount,
          '@skipped' => $skipped,
        ]
      );

      return [
        'success' => $successCount,
        'failed'  => 0,
        'skipped' => $skipped,
      ];

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('drupalbridge')->error(
        'Historical sync batch failed: @error',
        ['@error' => $e->getMessage()]
      );

      return [
        'success' => 0,
        'failed'  => count($inputs),
        'skipped' => $skipped,
      ];
    }
  }

  /**
 * Sync multiple contacts in a single batch request.
 * More efficient than individual syncs for bulk operations.
 */
public function bulkUpsertContacts(array $contactsData): array {
  if (empty($contactsData)) {
    return ['success' => 0, 'failed' => 0];
  }

  // Split into chunks of BATCH_SIZE
  $chunks  = array_chunk($contactsData, self::BATCH_SIZE);
  $success = 0;
  $failed  = 0;

  foreach ($chunks as $chunk) {
    $inputs = [];

    foreach ($chunk as $contact) {
      if (empty($contact['email'])) {
        $failed++;
        continue;
      }

      $properties = $contact;
      unset($properties['email']);

      $inputs[] = [
        'idProperty' => 'email',
        'id'         => $contact['email'],
        'properties' => $properties,
      ];
    }

    if (empty($inputs)) {
      continue;
    }

    try {
      // Add delay between chunks to respect rate limits
      if (count($chunks) > 1) {
        usleep(100000); // 100ms between chunks
      }

      $response = $this->hubspotClient->post(
        '/crm/v3/objects/contacts/batch/upsert',
        ['inputs' => $inputs]
      );

      $success += count($response['results'] ?? $inputs);

      $this->loggerFactory->get('drupalbridge')->notice(
        'Bulk upsert chunk success: @count contacts',
        ['@count' => count($inputs)]
      );

    }
    catch (\Exception $e) {
      $failed += count($inputs);

      $this->loggerFactory->get('drupalbridge')->error(
        'Bulk upsert chunk failed: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }

  return ['success' => $success, 'failed' => $failed];
}

}