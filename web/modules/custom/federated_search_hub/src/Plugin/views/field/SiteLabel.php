<?php

namespace Drupal\federated_search_hub\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\federated_search_hub\SiteLabelManager;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to display site labels instead of IDs.
 *
 * @ViewsField("federated_site_label")
 */
class SiteLabel extends FieldPluginBase {

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
  public function query() {
    // This field doesn't require any query modifications.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $site_id = $this->getValue($values);
    if (empty($site_id)) {
      return '';
    }

    return $this->siteLabelManager->getSiteLabel($site_id);
  }

}
