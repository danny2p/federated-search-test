<?php

namespace Drupal\federated_search_hub;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Manages site labels for federated search.
 */
class SiteLabelManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SiteLabelManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Get all site labels as an array.
   *
   * @return array
   *   Array of site_id => label mappings.
   */
  public function getSiteLabels() {
    $config = $this->configFactory->get('federated_search_hub.settings');
    $site_labels = $config->get('site_labels') ?: [];

    $labels = [];
    foreach ($site_labels as $site_label) {
      if (!empty($site_label['site_id']) && !empty($site_label['label'])) {
        $labels[$site_label['site_id']] = $site_label['label'];
      }
    }

    return $labels;
  }

  /**
   * Get the label for a specific site.
   *
   * @param string $site_id
   *   The site ID.
   *
   * @return string
   *   The site label, or the site ID if no label is configured.
   */
  public function getSiteLabel($site_id) {
    $labels = $this->getSiteLabels();
    return $labels[$site_id] ?? $site_id;
  }

  /**
   * Get options array for form select elements.
   *
   * @return array
   *   Array suitable for #options in form elements.
   */
  public function getSiteOptions() {
    return $this->getSiteLabels();
  }

}
