<?php

namespace Drupal\drupalbridge;

/**
 * Manages the DrupalBridge sync log.
 */
class SyncLogService {

  /**
   * Log a sync attempt.
   */
  public function log(
  string $email,
  string $status,
  string $source,
  ?string $error = NULL,
  ?string $hubspotId = NULL,
  ?array $rawValues = NULL
): void {

  \Drupal::database()->insert('drupalbridge_sync_log')
    ->fields([
      'email' => $email,
      'status' => $status,
      'source' => $source,
      'attempts' => 1,
      'error_message' => $error,
      'hubspot_contact_id' => $hubspotId,
      'raw_values' => $rawValues ? json_encode($rawValues) : NULL,
      'created' => time(),
      'updated' => time(),
    ])
    ->execute();
}

  /**
   * Get log entries with optional filters.
   */
  public function getEntries(
    array $filters = [],
    int $limit = 50,
    int $offset = 0
  ): array {
    $database = \Drupal::database();
    $query    = $database->select('drupalbridge_sync_log', 'l')
      ->fields('l')
      ->orderBy('l.updated', 'DESC')
      ->range($offset, $limit);

    if (!empty($filters['status'])) {
      $query->condition('l.status', $filters['status']);
    }

    if (!empty($filters['date_from'])) {
      $query->condition('l.created', $filters['date_from'], '>=');
    }

    if (!empty($filters['date_to'])) {
      $query->condition('l.created', $filters['date_to'], '<=');
    }

    if (!empty($filters['email'])) {
      $query->condition('l.email', '%' . $filters['email'] . '%', 'LIKE');
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Get total count for pagination.
   */
  public function getCount(array $filters = []): int {
  $database = \Drupal::database();

  $query = $database->select('drupalbridge_sync_log', 'l');

  if (!empty($filters['status'])) {
    $query->condition('status', $filters['status']); // ✅ no alias
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

  /**
   * Get all failed entries for bulk retry.
   */
  public function getFailedEntries(): array {
    return $this->getEntries(['status' => 'failed'], 1000);
  }

  /**
   * Delete a log entry.
   */
  public function delete(int $id): void {
    \Drupal::database()
      ->delete('drupalbridge_sync_log')
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Get all entries for CSV export.
   */
  public function getAllForExport(): array {
    return \Drupal::database()
      ->select('drupalbridge_sync_log', 'l')
      ->fields('l')
      ->orderBy('l.updated', 'DESC')
      ->execute()
      ->fetchAll();
  }

}