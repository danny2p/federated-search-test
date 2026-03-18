<?php

namespace Drupal\federated_search_hub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for federated search page.
 */
class SearchController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a SearchController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Displays the federated search page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   A render array.
   */
  public function search(Request $request) {
    $query = $request->query->get('q', '');
    $site_filter = $request->query->get('site', '');
    $page = $request->query->get('page', 0);
    $rows = 20;

    $build = [];

    // Search form
    $build['form'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['federated-search-form']],
    ];

    $build['form']['search'] = [
      '#type' => 'search',
      '#title' => $this->t('Search'),
      '#default_value' => $query,
      '#attributes' => [
        'placeholder' => $this->t('Enter search terms...'),
        'class' => ['form-control'],
      ],
    ];

    $build['form']['site'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by site'),
      '#options' => $this->getSiteOptions(),
      '#default_value' => $site_filter,
      '#empty_option' => $this->t('- All sites -'),
    ];

    $build['form']['submit'] = [
      '#type' => 'button',
      '#value' => $this->t('Search'),
      '#attributes' => [
        'onclick' => 'window.location.href = "/federated-search?q=" + document.querySelector("input[type=search]").value + "&site=" + document.querySelector("select").value; return false;',
        'class' => ['btn', 'btn-primary'],
      ],
    ];

    // Execute search if query provided
    if (!empty($query)) {
      try {
        $results = $this->executeSearch($query, $site_filter, $page, $rows);

        $build['results'] = [
          '#theme' => 'item_list',
          '#title' => $this->t('Search Results (@count found)', ['@count' => $results['total']]),
          '#items' => [],
          '#attributes' => ['class' => ['federated-search-results']],
        ];

        foreach ($results['docs'] as $doc) {
          $build['results']['#items'][] = [
            '#type' => 'inline_template',
            '#template' => '<div class="search-result">
              <h3><a href="{{ url }}">{{ title|raw }}</a></h3>
              <div class="search-snippet">{{ body|raw }}</div>
              <div class="search-meta">
                <span class="search-site">Site: {{ site_id }}</span>
              </div>
            </div>',
            '#context' => [
              'url' => $doc['url'],
              'title' => $doc['title'],
              'body' => $doc['body'],
              'site_id' => $doc['site_id'],
            ],
          ];
        }

        // Pager
        if ($results['total'] > $rows) {
          $build['pager'] = [
            '#type' => 'markup',
            '#markup' => $this->buildPager($results['total'], $page, $rows, $query, $site_filter),
          ];
        }
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Search error: @message', ['@message' => $e->getMessage()]));
      }
    }

    $build['#attached']['library'][] = 'federated_search_hub/search';

    return $build;
  }

  /**
   * Execute a search query.
   *
   * @param string $query
   *   The search query.
   * @param string $site_filter
   *   Optional site ID to filter by.
   * @param int $page
   *   Page number.
   * @param int $rows
   *   Number of results per page.
   *
   * @return array
   *   Search results.
   */
  protected function executeSearch($query, $site_filter = '', $page = 0, $rows = 20) {
    $server_storage = $this->entityTypeManager->getStorage('search_api_server');
    $server = $server_storage->load('pantheon_search');

    if (!$server) {
      throw new \Exception('Search server not found');
    }

    $backend = $server->getBackend();
    $connector = $backend->getSolrConnector();

    $solr_query = $connector->getSelectQuery();

    // Build query - search across title and body fields
    $search_query = 'tm_title:' . $query . ' OR tm_body:' . $query;

    if (!empty($site_filter)) {
      $search_query .= ' AND ss_site_id:' . $site_filter;
    }

    $solr_query->setQuery($search_query);

    // Add filter to only get federated content
    $solr_query->createFilterQuery('federated')->setQuery('id:federated\:*');

    $solr_query->setStart($page * $rows);
    $solr_query->setRows($rows);

    // Enable highlighting
    $highlighting = $solr_query->getHighlighting();
    $highlighting->setFields(['tm_title', 'tm_body']);
    $highlighting->setSimplePrefix('<strong>');
    $highlighting->setSimplePostfix('</strong>');
    $highlighting->setFragSize(300);
    $highlighting->setSnippets(1);

    $result = $connector->execute($solr_query);
    $highlighting_data = $result->getHighlighting();

    $docs = [];
    foreach ($result as $doc) {
      $doc_id = $doc->id;
      $highlighted_doc = $highlighting_data->getResult($doc_id);

      // Use highlighted title if available
      $title = $doc->tm_title[0] ?? 'Untitled';
      if ($highlighted_doc && $highlighted_title = $highlighted_doc->getField('tm_title')) {
        $title = $highlighted_title[0];
      }

      // Use highlighted body snippet if available
      $body = '';
      if ($highlighted_doc && $highlighted_body = $highlighted_doc->getField('tm_body')) {
        $body = $highlighted_body[0];
      }
      elseif (isset($doc->tm_body[0])) {
        $body = substr($doc->tm_body[0], 0, 300) . '...';
      }

      $docs[] = [
        'title' => $title,
        'body' => $body,
        'url' => $doc->ss_url ?? '',
        'site_id' => $doc->ss_site_id ?? '',
      ];
    }

    return [
      'total' => $result->getNumFound(),
      'docs' => $docs,
    ];
  }

  /**
   * Get available site options for filter.
   *
   * @return array
   *   Array of site options.
   */
  protected function getSiteOptions() {
    // Get unique site IDs from Solr
    try {
      $server_storage = $this->entityTypeManager->getStorage('search_api_server');
      $server = $server_storage->load('pantheon_search');
      $backend = $server->getBackend();
      $connector = $backend->getSolrConnector();

      $query = $connector->getSelectQuery();
      $query->setQuery('id:federated\:*');
      $query->setRows(0);

      $facetSet = $query->getFacetSet();
      $facetSet->createFacetField('sites')->setField('ss_site_id');

      $result = $connector->execute($query);

      $options = [];
      $facet = $result->getFacetSet()->getFacet('sites');
      foreach ($facet as $site => $count) {
        $options[$site] = $site . ' (' . $count . ')';
      }

      return $options;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Build a simple pager.
   *
   * @param int $total
   *   Total number of results.
   * @param int $page
   *   Current page.
   * @param int $rows
   *   Results per page.
   * @param string $query
   *   Search query.
   * @param string $site_filter
   *   Site filter.
   *
   * @return string
   *   Pager HTML.
   */
  protected function buildPager($total, $page, $rows, $query, $site_filter) {
    $total_pages = ceil($total / $rows);
    $html = '<div class="pager">';

    if ($page > 0) {
      $prev = $page - 1;
      $html .= '<a href="/federated-search?q=' . urlencode($query) . '&site=' . urlencode($site_filter) . '&page=' . $prev . '">Previous</a> ';
    }

    $html .= 'Page ' . ($page + 1) . ' of ' . $total_pages;

    if ($page < $total_pages - 1) {
      $next = $page + 1;
      $html .= ' <a href="/federated-search?q=' . urlencode($query) . '&site=' . urlencode($site_filter) . '&page=' . $next . '">Next</a>';
    }

    $html .= '</div>';
    return $html;
  }

}
