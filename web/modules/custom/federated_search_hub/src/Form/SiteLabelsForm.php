<?php

namespace Drupal\federated_search_hub\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure site labels for federated search.
 */
class SiteLabelsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['federated_search_hub.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'federated_search_hub_site_labels';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('federated_search_hub.settings');
    $site_labels = $config->get('site_labels') ?: [];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure user-friendly labels for federated sites. These labels will be used in search filters and displays.') . '</p>',
    ];

    $form['hub_site_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hub Site ID'),
      '#description' => $this->t('The site ID to use when indexing content from this hub site. This should match one of the site IDs in the labels below.'),
      '#default_value' => $config->get('hub_site_id') ?: 'danny-drupal-cms',
      '#required' => TRUE,
    ];

    $form['site_labels'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Site ID'),
        $this->t('Display Label'),
        $this->t('Weight'),
      ],
      '#empty' => $this->t('No site labels configured.'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'site-label-weight',
        ],
      ],
    ];

    // Add existing site labels
    foreach ($site_labels as $delta => $site_label) {
      $form['site_labels'][$delta] = $this->buildSiteLabelRow($site_label, $delta);
    }

    // Add 3 empty rows for new entries
    $num_labels = count($site_labels);
    for ($i = 0; $i < 3; $i++) {
      $delta = $num_labels + $i;
      $form['site_labels'][$delta] = $this->buildSiteLabelRow([], $delta);
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Build a site label row.
   *
   * @param array $site_label
   *   The site label data.
   * @param int $delta
   *   The row delta.
   *
   * @return array
   *   Form row.
   */
  protected function buildSiteLabelRow(array $site_label, $delta) {
    $row = [
      '#attributes' => ['class' => ['draggable']],
      '#weight' => $delta,
    ];

    $row['site_id'] = [
      '#type' => 'textfield',
      '#default_value' => $site_label['site_id'] ?? '',
      '#size' => 30,
      '#placeholder' => $this->t('e.g., marketing-site'),
    ];

    $row['label'] = [
      '#type' => 'textfield',
      '#default_value' => $site_label['label'] ?? '',
      '#size' => 40,
      '#placeholder' => $this->t('e.g., Marketing Website'),
    ];

    $row['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight for @title', ['@title' => $site_label['label'] ?? '']),
      '#title_display' => 'invisible',
      '#default_value' => $delta,
      '#attributes' => ['class' => ['site-label-weight']],
    ];

    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $site_labels = [];

    foreach ($form_state->getValue('site_labels') as $row) {
      // Only save rows with both site_id and label filled in
      if (!empty($row['site_id']) && !empty($row['label'])) {
        $site_labels[] = [
          'site_id' => trim($row['site_id']),
          'label' => trim($row['label']),
        ];
      }
    }

    $this->config('federated_search_hub.settings')
      ->set('hub_site_id', $form_state->getValue('hub_site_id'))
      ->set('site_labels', $site_labels)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
