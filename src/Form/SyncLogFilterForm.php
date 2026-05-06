<?php

namespace Drupal\drupalbridge\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Filter form for sync log.
 */
class SyncLogFilterForm extends FormBase {

  public function getFormId(): string {
    return 'drupalbridge_sync_log_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = \Drupal::request();

    $form['#method'] = 'get';

    $form['filters'] = [
      '#type'       => 'container',
      '#attributes' => ['style' => 'display: flex; gap: 15px; flex-wrap: wrap;'],
    ];

    $form['filters']['status'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Status'),
      '#options'       => [
        ''             => $this->t('-- All --'),
        'synced'       => $this->t('Synced'),
        'failed'       => $this->t('Failed'),
        'queued'       => $this->t('Queued'),
        'gdpr_deleted' => $this->t('GDPR Deleted'),
      ],
      '#default_value' => $request->query->get('status', ''),
    ];

    $form['filters']['email'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Email'),
      '#default_value' => $request->query->get('email', ''),
      '#size'          => 30,
    ];

    $form['filters']['date_from'] = [
      '#type'          => 'date',
      '#title'         => $this->t('From Date'),
      '#default_value' => $request->query->get('date_from', ''),
    ];

    $form['filters']['date_to'] = [
      '#type'          => 'date',
      '#title'         => $this->t('To Date'),
      '#default_value' => $request->query->get('date_to', ''),
    ];

    $form['filters']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Filter'),
    ];

    $form['filters']['reset'] = [
      '#type'  => 'link',
      '#title' => $this->t('Reset'),
      '#url'   => \Drupal\Core\Url::fromRoute('drupalbridge.sync_log'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Form uses GET method so no submit handling needed
  }

}