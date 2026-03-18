<?php

namespace Drupal\federated_search_hub\Plugin\search_api\datasource;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\Plugin\DataType\Map;
use Drupal\search_api\Datasource\DatasourcePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Represents federated content from remote sites.
 *
 * @SearchApiDatasource(
 *   id = "federated_content",
 *   label = @Translation("Federated Content"),
 *   description = @Translation("Content indexed from remote federated sites.")
 * )
 */
class FederatedContent extends DatasourcePluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $datasource = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $datasource->setEntityTypeManager($container->get('entity_type.manager'));
    return $datasource;
  }

  /**
   * Sets the entity type manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions() {
    $properties = [];

    $properties['id'] = new DataDefinition();
    $properties['id']->setDataType('string')
      ->setLabel($this->t('Federated ID'))
      ->setDescription($this->t('The unique ID of the federated content.'));

    $properties['title'] = new DataDefinition();
    $properties['title']->setDataType('string')
      ->setLabel($this->t('Title'))
      ->setDescription($this->t('The title of the content.'));

    $properties['body'] = new DataDefinition();
    $properties['body']->setDataType('string')
      ->setLabel($this->t('Body'))
      ->setDescription($this->t('The body content.'));

    $properties['summary'] = new DataDefinition();
    $properties['summary']->setDataType('string')
      ->setLabel($this->t('Summary'))
      ->setDescription($this->t('The content summary.'));

    $properties['url'] = new DataDefinition();
    $properties['url']->setDataType('string')
      ->setLabel($this->t('URL'))
      ->setDescription($this->t('The URL to the original content.'));

    $properties['site_id'] = new DataDefinition();
    $properties['site_id']->setDataType('string')
      ->setLabel($this->t('Site ID'))
      ->setDescription($this->t('The ID of the site this content is from.'));

    $properties['site_url'] = new DataDefinition();
    $properties['site_url']->setDataType('string')
      ->setLabel($this->t('Site URL'))
      ->setDescription($this->t('The base URL of the source site.'));

    $properties['created'] = new DataDefinition();
    $properties['created']->setDataType('timestamp')
      ->setLabel($this->t('Created'))
      ->setDescription($this->t('The creation date.'));

    $properties['changed'] = new DataDefinition();
    $properties['changed']->setDataType('timestamp')
      ->setLabel($this->t('Changed'))
      ->setDescription($this->t('The last modified date.'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids) {
    if (empty($ids)) {
      return [];
    }

    try {
      $server_storage = $this->entityTypeManager->getStorage('search_api_server');
      $server = $server_storage->load('pantheon_search');

      if (!$server) {
        return [];
      }

      $backend = $server->getBackend();
      $connector = $backend->getSolrConnector();

      // Query Solr for these specific documents
      $query = $connector->getSelectQuery();
      $id_queries = [];
      $solr_ids = [];

      foreach ($ids as $combined_id) {
        // Extract Solr ID from Search API format (datasource_id/solr_id)
        $solr_id = $combined_id;
        if (strpos($combined_id, '/') !== FALSE) {
          list(, $solr_id) = explode('/', $combined_id, 2);
        }
        $solr_ids[$solr_id] = $combined_id;
        $id_queries[] = 'id:' . addcslashes($solr_id, '":');
      }

      $query->setQuery(implode(' OR ', $id_queries));
      $query->setRows(count($ids));

      $result = $connector->execute($query);

      $items = [];

      // Create a MapDataDefinition with property definitions
      $map_definition = MapDataDefinition::create();
      $property_definitions = $this->getPropertyDefinitions();
      foreach ($property_definitions as $name => $definition) {
        $map_definition->setPropertyDefinition($name, $definition);
      }

      foreach ($result as $doc) {
        // Create a Map object (implements ComplexDataInterface) to hold the document data
        // Extract text fields - they may have different language codes (en, und, etc.)
        $title = '';
        $body = '';
        $summary = '';

        // Find title field (tm_X3b_en_title, tm_X3b_und_title, or tm_title)
        foreach (['tm_X3b_en_title', 'tm_X3b_und_title', 'tm_title'] as $field) {
          if (isset($doc->{$field}[0])) {
            $title = $doc->{$field}[0];
            break;
          }
        }

        // Find body field
        foreach (['tm_X3b_en_body', 'tm_X3b_und_body', 'tm_body'] as $field) {
          if (isset($doc->{$field}[0])) {
            $body = $doc->{$field}[0];
            break;
          }
        }

        // Find summary field
        foreach (['tm_X3b_en_summary', 'tm_X3b_und_summary', 'tm_summary'] as $field) {
          if (isset($doc->{$field}[0])) {
            $summary = $doc->{$field}[0];
            break;
          }
        }

        $data = [
          'id' => $doc->id ?? '',
          'title' => $title,
          'body' => $body,
          'summary' => $summary,
          'url' => $doc->ss_url ?? '',
          'site_id' => $doc->ss_site_id ?? '',
          'site_url' => $doc->ss_site_url ?? '',
          'created' => isset($doc->ds_created) ? strtotime($doc->ds_created) : 0,
          'changed' => isset($doc->ds_changed) ? strtotime($doc->ds_changed) : 0,
        ];

        $item = Map::createInstance($map_definition);
        $item->setValue($data);

        // Use the Search API combined ID as the key
        $combined_id = $solr_ids[$doc->id] ?? $doc->id;
        $items[$combined_id] = $item;
      }

      return $items;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getItemId($item) {
    if ($item instanceof Map) {
      $id = $item->get('id')->getValue();
      // Return in Search API format: datasource_id/raw_id
      return $this->getPluginId() . '/' . $id;
    }
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemIds($datasource_id = NULL) {
    // Query Solr for all federated document IDs
    try {
      $server_storage = $this->entityTypeManager->getStorage('search_api_server');
      $server = $server_storage->load('pantheon_search');

      if (!$server) {
        return [];
      }

      $backend = $server->getBackend();
      $connector = $backend->getSolrConnector();

      $query = $connector->getSelectQuery();
      $query->setQuery('id:federated\:*');
      $query->setRows(10000);
      $query->setFields(['id']);

      $result = $connector->execute($query);

      $ids = [];
      foreach ($result as $doc) {
        // Return in Search API format: datasource_id/raw_id
        $ids[] = $this->getPluginId() . '/' . $doc->id;
      }

      return $ids;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldValues($item, $field_name) {
    if (!$item instanceof Map) {
      return [];
    }

    try {
      $value = $item->get($field_name);
      if ($value) {
        return [$value->getValue()];
      }
    }
    catch (\Exception $e) {
      // Field doesn't exist
    }

    return [];
  }

}
