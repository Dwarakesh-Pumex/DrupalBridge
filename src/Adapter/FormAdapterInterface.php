<?php

namespace Drupal\drupalbridge\Adapter;

/**
 * Interface for DrupalBridge form adapters.
 * Each adapter normalises form data into a standard payload.
 */
interface FormAdapterInterface {

  /**
   * Check if this adapter can handle the given form.
   *
   * @param string $formId
   *   The form identifier.
   *
   * @return bool
   *   TRUE if this adapter handles this form.
   */
  public function supports(string $formId): bool;

  /**
   * Normalise form submission data into standard payload.
   *
   * @param array $rawData
   *   Raw submission data from the form builder.
   *
   * @return array
   *   Normalised payload with email, firstname, lastname etc.
   */
  public function normalise(array $rawData): array;

  /**
   * Get the adapter name for logging.
   *
   * @return string
   *   Human readable adapter name.
   */
  public function getName(): string;

}