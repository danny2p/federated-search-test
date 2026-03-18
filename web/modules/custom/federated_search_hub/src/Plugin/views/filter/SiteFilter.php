<?php

namespace Drupal\federated_search_hub\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\federated_search_hub\SiteLabelManager;
use Drupal\search_api\Plugin\views\filter\SearchApiOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter handler for federated site selection with labels.
 *
 * @ViewsFilter("federated_site_filter")
 */
class SiteFilter extends SearchApiOptions {

  /**
   * The site label manager.
   *
   * @var \Drupal\federated_search_hub\SiteLabelManager
   */
  protected $siteLabelManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->siteLabelManager = $container->get('federated_search_hub.site_label_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }

    // Get site labels from the configuration.
    $this->valueOptions = $this->siteLabelManager->getSiteOptions();

    // If no labels are configured, fall back to getting unique site IDs from Solr.
    if (empty($this->valueOptions)) {
      $this->valueOptions = parent::getValueOptions();
    }

    return $this->valueOptions;
  }

}
