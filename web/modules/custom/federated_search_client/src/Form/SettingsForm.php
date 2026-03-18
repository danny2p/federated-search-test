<?php

namespace Drupal\federated_search_client\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Federated Search Client settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['federated_search_client.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'federated_search_client_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('federated_search_client.settings');

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure this site to sync content to a federated search hub. The hub must have the Federated Search Hub module installed.') . '</p>',
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic sync'),
      '#description' => $this->t('When enabled, content will be synced to the hub on cron runs.'),
      '#default_value' => $config->get('enabled'),
    ];

    $form['connection'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Hub Connection'),
    ];

    $form['connection']['hub_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Hub URL'),
      '#description' => $this->t('The base URL of your federated search hub site (e.g., https://danny-drupal-cms.pantheonsite.io)'),
      '#default_value' => $config->get('hub_url'),
      '#required' => TRUE,
    ];

    $form['connection']['site_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site ID'),
      '#description' => $this->t('Unique identifier for this site. Use lowercase letters, numbers, and hyphens only (e.g., marketing, blog, docs).'),
      '#default_value' => $config->get('site_id'),
      '#required' => TRUE,
      '#pattern' => '[a-z0-9-]+',
    ];

    $form['connection']['site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Site URL'),
      '#description' => $this->t('This site\'s base URL. Leave empty to auto-detect.'),
      '#default_value' => $config->get('site_url'),
    ];

    $form['connection']['api_key_status'] = [
      '#markup' => '<div class="messages messages--' . $this->getApiKeyStatus()['status'] . '">' .
        $this->t('API Key Status: @message', ['@message' => $this->getApiKeyStatus()['message']]) .
        '</div>',
    ];

    $form['content'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Content Settings'),
    ];

    // Get available content types
    $content_types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $content_type_options = [];
    foreach ($content_types as $type) {
      $content_type_options[$type->id()] = $type->label();
    }

    $form['content']['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types to index'),
      '#description' => $this->t('Select which content types to sync. Leave empty to sync all published content.'),
      '#options' => $content_type_options,
      '#default_value' => $config->get('content_types') ?: [],
    ];

    $form['sync'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sync Settings'),
    ];

    $form['sync']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#description' => $this->t('Number of items to sync per cron run.'),
      '#default_value' => $config->get('batch_size') ?: 50,
      '#min' => 1,
      '#max' => 500,
      '#required' => TRUE,
    ];

    $form['sync']['sync_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Sync interval'),
      '#description' => $this->t('How often to sync content on cron runs.'),
      '#options' => [
        900 => $this->t('Every 15 minutes'),
        1800 => $this->t('Every 30 minutes'),
        3600 => $this->t('Every hour'),
        7200 => $this->t('Every 2 hours'),
        21600 => $this->t('Every 6 hours'),
        43200 => $this->t('Every 12 hours'),
        86400 => $this->t('Once daily'),
      ],
      '#default_value' => $config->get('sync_interval') ?: 3600,
      '#required' => TRUE,
    ];

    $last_sync = \Drupal::state()->get('federated_search_client.last_sync', 0);
    if ($last_sync > 0) {
      $form['sync']['last_sync'] = [
        '#markup' => '<p>' . $this->t('Last sync: @time', [
          '@time' => \Drupal::service('date.formatter')->format($last_sync, 'long'),
        ]) . '</p>',
      ];
    }

    $form['actions']['sync_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync Now'),
      '#submit' => ['::syncNow'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $site_id = $form_state->getValue('site_id');

    if (!preg_match('/^[a-z0-9-]+$/', $site_id)) {
      $form_state->setErrorByName('site_id', $this->t('Site ID must contain only lowercase letters, numbers, and hyphens.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('federated_search_client.settings');

    // Filter out unchecked content types
    $content_types = array_filter($form_state->getValue('content_types'));

    $config
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('hub_url', rtrim($form_state->getValue('hub_url'), '/'))
      ->set('site_id', $form_state->getValue('site_id'))
      ->set('site_url', $form_state->getValue('site_url'))
      ->set('content_types', array_values($content_types))
      ->set('batch_size', $form_state->getValue('batch_size'))
      ->set('sync_interval', $form_state->getValue('sync_interval'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for "Sync Now" button.
   */
  public function syncNow(array &$form, FormStateInterface $form_state) {
    try {
      // Save the form first
      $this->submitForm($form, $form_state);

      // Run the sync
      $exporter = \Drupal::service('federated_search_client.exporter');
      $result = $exporter->exportBatch();

      $this->messenger()->addStatus($this->t('Successfully synced @count items to federated search hub.', [
        '@count' => $result['exported'],
      ]));

      // Update last sync time
      \Drupal::state()->set('federated_search_client.last_sync', time());

      if (!empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
          $this->messenger()->addWarning($error);
        }
      }

    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Sync failed: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    $form_state->setRedirect('federated_search_client.settings');
  }

  /**
   * Get API key status.
   *
   * @return array
   *   Status array with 'status' and 'message' keys.
   */
  protected function getApiKeyStatus() {
    if (function_exists('pantheon_get_secret')) {
      $api_key = pantheon_get_secret('FEDERATED_SEARCH_API_KEY');
    }
    else {
      $api_key = getenv('FEDERATED_SEARCH_API_KEY');
    }

    if (empty($api_key)) {
      return [
        'status' => 'error',
        'message' => $this->t('Not configured. Set FEDERATED_SEARCH_API_KEY in Pantheon Secrets.'),
      ];
    }

    return [
      'status' => 'status',
      'message' => $this->t('Configured'),
    ];
  }

}
