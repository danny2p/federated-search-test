<?php

/**
 * @file
 * Lightweight Solr search proxy for federated search.
 *
 * This script handles search queries from remote Pantheon sites without
 * bootstrapping Drupal, providing maximum performance for search operations.
 *
 * Authentication: Uses Pantheon Secrets Manager
 * Secret Name: FEDERATED_SEARCH_API_KEY
 *
 * Usage:
 *   GET /solr-proxy.php?q=keyword&site=site-id&start=0&rows=10
 *   Header: X-Federated-Search-Key: {secret}
 */

// Prevent direct access without parameters
if (php_sapi_name() === 'cli') {
  echo "This script must be run via HTTP.\n";
  exit(1);
}

// Set JSON response header
header('Content-Type: application/json');

/**
 * Send error response and exit.
 */
function send_error($code, $message) {
  http_response_code($code);
  echo json_encode(['error' => $message, 'status' => $code]);
  exit;
}

/**
 * Authenticate the request using Pantheon Secret.
 */
function authenticate_request() {
  // Get the provided API key from header
  $provided_key = $_SERVER['HTTP_X_FEDERATED_SEARCH_KEY'] ?? '';

  if (empty($provided_key)) {
    send_error(401, 'Missing X-Federated-Search-Key header');
  }

  // Get the valid key from Pantheon Secrets
  // Note: pantheon_get_secret() is available in Pantheon's PHP environment
  if (function_exists('pantheon_get_secret')) {
    $valid_key = pantheon_get_secret('FEDERATED_SEARCH_API_KEY');
  } else {
    // Fallback for local development - use environment variable
    $valid_key = getenv('FEDERATED_SEARCH_API_KEY');
  }

  if (empty($valid_key)) {
    send_error(500, 'Federated search API key not configured');
  }

  // Use hash_equals to prevent timing attacks
  if (!hash_equals($valid_key, $provided_key)) {
    send_error(403, 'Invalid API key');
  }
}

/**
 * Get Solr connection details from Pantheon environment.
 */
function get_solr_connection() {
  $solr_host = getenv('PANTHEON_INDEX_HOST');
  $solr_port = getenv('PANTHEON_INDEX_PORT');
  $solr_path = getenv('PANTHEON_INDEX_PATH');
  $solr_core = getenv('PANTHEON_INDEX_CORE');

  if (empty($solr_host) || empty($solr_port) || empty($solr_path) || empty($solr_core)) {
    send_error(500, 'Solr connection not configured');
  }

  return [
    'host' => $solr_host,
    'port' => $solr_port,
    'path' => $solr_path,
    'core' => $solr_core,
  ];
}

/**
 * Build Solr query parameters.
 */
function build_solr_params() {
  // Get search query (default to wildcard if empty)
  $query = $_GET['q'] ?? '*:*';

  // Sanitize and validate query
  $query = trim($query);
  if (empty($query)) {
    $query = '*:*';
  }

  // Get pagination parameters
  $start = isset($_GET['start']) ? max(0, intval($_GET['start'])) : 0;
  $rows = isset($_GET['rows']) ? min(100, max(1, intval($_GET['rows']))) : 10;

  // Build base parameters
  $params = [
    'q' => $query,
    'start' => $start,
    'rows' => $rows,
    'wt' => 'json',
    'fl' => '*,score', // Return all fields plus relevance score
  ];

  // Add site filter if specified
  if (!empty($_GET['site'])) {
    $site_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['site']);
    $params['fq'] = 'ss_site_id:' . $site_id;
  }

  // Add additional filters if provided
  if (!empty($_GET['fq'])) {
    // Allow passing additional filter queries
    if (is_array($_GET['fq'])) {
      foreach ($_GET['fq'] as $fq) {
        $params['fq'][] = $fq;
      }
    } else {
      $params['fq'] = $_GET['fq'];
    }
  }

  // Add sorting if specified
  if (!empty($_GET['sort'])) {
    $params['sort'] = $_GET['sort'];
  }

  // Add highlighting if requested
  if (!empty($_GET['hl']) && $_GET['hl'] === 'true') {
    $params['hl'] = 'true';
    $params['hl.fl'] = 'tm_title,tm_body';
    $params['hl.simple.pre'] = '<strong>';
    $params['hl.simple.post'] = '</strong>';
    $params['hl.snippets'] = 3;
    $params['hl.fragsize'] = 256;
  }

  return $params;
}

/**
 * Execute Solr query.
 */
function execute_solr_query($solr_url, $params) {
  // Build query string
  $query_string = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  $full_url = $solr_url . '?' . $query_string;

  // Initialize cURL
  if (function_exists('pantheon_curl_setup')) {
    // Use Pantheon's curl setup for proper SSL configuration
    list($ch, $opts) = pantheon_curl_setup($full_url);
  } else {
    // Standard cURL setup for local development
    $ch = curl_init($full_url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
  }

  // Set cURL options
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);

  // Execute request
  $response = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  // Handle errors
  if ($response === false) {
    send_error(502, 'Solr query failed: ' . $error);
  }

  if ($http_code !== 200) {
    send_error($http_code, 'Solr returned error: ' . $http_code);
  }

  return $response;
}

// Main execution
try {
  // Step 1: Authenticate the request
  authenticate_request();

  // Step 2: Get Solr connection details
  $solr = get_solr_connection();
  $solr_url = "https://{$solr['host']}:{$solr['port']}/{$solr['path']}/{$solr['core']}/select";

  // Step 3: Build query parameters
  $params = build_solr_params();

  // Step 4: Execute query
  $response = execute_solr_query($solr_url, $params);

  // Step 5: Return results
  http_response_code(200);
  echo $response;

} catch (Exception $e) {
  send_error(500, 'Internal error: ' . $e->getMessage());
}
