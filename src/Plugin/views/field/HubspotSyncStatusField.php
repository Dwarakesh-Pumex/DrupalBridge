<?php

namespace Drupal\drupalbridge\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * @ViewsField("hubspot_sync_status_field")
 */
class HubspotSyncStatusField extends FieldPluginBase {

  public function render(ResultRow $values) {
    $uid = $values->_entity->id();

    $status = \Drupal::service('user.data')->get(
      'drupalbridge',
      $uid,
      'hubspot_sync_status'
    );

    return $status ? ucfirst($status) : 'Not synced';
  }

}