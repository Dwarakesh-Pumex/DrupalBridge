<?php

namespace Drupal\drupalbridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Handles HubSpot contact deletion webhooks.
 * Called by HubSpot when a contact is deleted (GDPR right to erasure).
 */
class ContactDeletionController extends ControllerBase {

  /**
   * Handle incoming webhook from HubSpot.
   */
  public function handle(Request $request): JsonResponse {

    // Step 1 — Validate signature
    if (!$this->validateSignature($request)) {
      \Drupal::logger('drupalbridge')->warning(
        'HubSpot webhook signature validation failed. IP: @ip',
        ['@ip' => $request->getClientIp()]
      );
      return new JsonResponse(['error' => 'Invalid signature'], 401);
    }

    // Step 2 — Parse payload
    $body = $request->getContent();
    $events = json_decode($body, TRUE);

    if (empty($events) || !is_array($events)) {
      \Drupal::logger('drupalbridge')->warning(
        'HubSpot webhook received empty or invalid payload.'
      );
      return new JsonResponse(['error' => 'Invalid payload'], 400);
    }

    // Step 3 — Process each event
    foreach ($events as $event) {
      $eventType = $event['subscriptionType'] ?? '';
      $contactId = $event['objectId'] ?? NULL;

      \Drupal::logger('drupalbridge')->notice(
        'HubSpot webhook received. Type: @type. Contact ID: @id',
        [
          '@type' => $eventType,
          '@id'   => $contactId,
        ]
      );

      if ($eventType === 'contact.deletion' && $contactId) {
        $this->processContactDeletion($contactId);
      }
    }

    return new JsonResponse(['status' => 'ok'], 200);
  }

  /**
   * Validate HubSpot webhook signature v3.
   */
  private function validateSignature(Request $request): bool {
    $clientSecret = \Drupal::config('drupalbridge.settings')
      ->get('hubspot_client_secret') ?? '';

    // Skip validation if no secret configured
    if (empty($clientSecret)) {
      \Drupal::logger('drupalbridge')->warning(
        'HubSpot webhook signature validation skipped — no client secret configured.'
      );
      return TRUE;
    }

    $signature = $request->headers->get('X-HubSpot-Signature-v3');

    if (empty($signature)) {
      return FALSE;
    }

    // Build the string to hash
    $method      = $request->getMethod();
    $url         = $request->getUri();
    $body        = $request->getContent();
    $timestamp   = $request->headers->get('X-HubSpot-Request-Timestamp');

    // Reject requests older than 5 minutes
    if ($timestamp && (time() * 1000 - (int) $timestamp) > 300000) {
      \Drupal::logger('drupalbridge')->warning(
        'HubSpot webhook rejected — timestamp too old.'
      );
      return FALSE;
    }

    $stringToHash = $method . $url . $body . $timestamp;
    $expectedHash = base64_encode(
      hash_hmac('sha256', $stringToHash, $clientSecret, TRUE)
    );

    return hash_equals($expectedHash, $signature);
  }

  /**
   * Process a contact deletion event.
   */
  private function processContactDeletion(int $contactId): void {
    // Remove from sync log — delete any queue items for this contact
    $database = \Drupal::database();

    // Log the deletion
    \Drupal::logger('drupalbridge')->notice(
      'Processing GDPR contact deletion for HubSpot contact ID: @id',
      ['@id' => $contactId]
    );

    // Remove from drupalbridge sync log table if it exists
    try {
      if ($database->schema()->tableExists('drupalbridge_sync_log')) {
        $database->delete('drupalbridge_sync_log')
          ->condition('hubspot_contact_id', $contactId)
          ->execute();

        \Drupal::logger('drupalbridge')->notice(
          'Removed HubSpot contact @id from sync log.',
          ['@id' => $contactId]
        );
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('drupalbridge')->error(
        'Failed to remove contact @id from sync log: @error',
        [
          '@id'    => $contactId,
          '@error' => $e->getMessage(),
        ]
      );
    }

    // Also anonymise any user data linked to this contact
    $this->anonymiseLinkedUser($contactId);
  }

  /**
   * Anonymise Drupal user linked to deleted HubSpot contact.
   */
  private function anonymiseLinkedUser(int $contactId): void {
    // Find user with this HubSpot contact ID stored in user.data
    $database = \Drupal::database();

    try {
      $results = $database->select('users_data', 'ud')
        ->fields('ud', ['uid', 'value'])
        ->condition('ud.module', 'drupalbridge')
        ->condition('ud.name', 'hubspot_contact_id')
        ->execute()
        ->fetchAll();

      foreach ($results as $row) {
        $storedId = unserialize($row->value);
        if ($storedId == $contactId) {
          // Mark user as gdpr deleted in user data
          \Drupal::service('user.data')->set(
            'drupalbridge',
            $row->uid,
            'hubspot_sync_status',
            'gdpr_deleted'
          );
          \Drupal::service('user.data')->set(
            'drupalbridge',
            $row->uid,
            'hubspot_contact_id',
            NULL
          );

          \Drupal::logger('drupalbridge')->notice(
            'Anonymised Drupal user @uid linked to deleted HubSpot contact @id.',
            [
              '@uid' => $row->uid,
              '@id'  => $contactId,
            ]
          );
        }
      }
    }
    catch (\Exception $e) {
      // User data table may not have this data — that is fine
    }
  }

}