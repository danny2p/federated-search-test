<?php
/**
 * Federated Search Widget - Server-Side Implementation
 *
 * This widget performs federated search using server-side PHP,
 * keeping the API key secure and avoiding CORS issues.
 */

// Configuration
$hub_url = 'https://dev-your-site-name.pantheonsite.io';

// Get API key from Pantheon Secrets (server-side only, never exposed to client)
if (function_exists('pantheon_get_secret')) {
  $api_key = pantheon_get_secret('FEDERATED_SEARCH_API_KEY');
} else {
  $api_key = getenv('FEDERATED_SEARCH_API_KEY');
}

// Initialize variables
$search_results = null;
$error_message = null;
$search_query = '';
$current_page = 0;
$results_per_page = 10;

// Handle search request
if (!empty($_GET['q'])) {
  $search_query = trim($_GET['q']);

  // Validate query length to prevent abuse
  if (strlen($search_query) > 500) {
    $error_message = 'Search query too long (maximum 500 characters)';
  } else {
    $current_page = isset($_GET['page']) ? max(0, intval($_GET['page'])) : 0;
    $start = $current_page * $results_per_page;

    // Build query parameters
    $params = [
      'q' => $search_query,
      'start' => $start,
      'rows' => $results_per_page,
      'hl' => 'true',
    ];

    // Add site filter if provided (sanitize to alphanumeric, underscore, hyphen only)
    if (!empty($_GET['site'])) {
      $site_filter = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['site']);
      if (!empty($site_filter)) {
        $params['site'] = $site_filter;
      }
    }

    // Build URL
    $query_url = $hub_url . '/solr-proxy.php?' . http_build_query($params);

    // Make request to hub
    $ch = curl_init($query_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'X-Federated-Search-Key: ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && $http_code === 200) {
      $search_results = json_decode($response, true);
    } else {
      $error_data = json_decode($response, true);
      // Sanitize error message to prevent information disclosure
      $error_message = !empty($error_data['error']) ? 'Search error occurred' : 'Search request failed';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Federated Search</title>
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      max-width: 900px;
      margin: 0 auto;
      padding: 2rem;
      background: #f5f5f5;
    }

    .search-header {
      background: white;
      padding: 2rem;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      margin-bottom: 2rem;
    }

    h1 {
      margin: 0 0 1.5rem 0;
      color: #333;
    }

    .search-form {
      display: flex;
      gap: 0.5rem;
    }

    .search-input {
      flex: 1;
      padding: 0.75rem 1rem;
      font-size: 1rem;
      border: 2px solid #ddd;
      border-radius: 4px;
      outline: none;
    }

    .search-input:focus {
      border-color: #0073aa;
    }

    .search-button {
      padding: 0.75rem 2rem;
      font-size: 1rem;
      background: #0073aa;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 600;
    }

    .search-button:hover {
      background: #005177;
    }

    .search-stats {
      color: #666;
      margin-bottom: 1rem;
      font-size: 0.9rem;
    }

    .error-message {
      background: #f8d7da;
      color: #721c24;
      padding: 1rem;
      border-radius: 4px;
      border-left: 4px solid #f5c6cb;
      margin-bottom: 1rem;
    }

    .no-results {
      background: white;
      padding: 2rem;
      border-radius: 8px;
      text-align: center;
      color: #666;
    }

    .result {
      background: white;
      padding: 1.5rem;
      margin-bottom: 1rem;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      transition: box-shadow 0.2s;
    }

    .result:hover {
      box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .result-title {
      margin: 0 0 0.5rem 0;
      font-size: 1.25rem;
    }

    .result-title a {
      color: #0073aa;
      text-decoration: none;
    }

    .result-title a:hover {
      text-decoration: underline;
    }

    .result-meta {
      color: #666;
      font-size: 0.875rem;
      margin-bottom: 0.5rem;
    }

    .result-snippet {
      color: #333;
      line-height: 1.6;
    }

    .result-snippet strong {
      background: #ffeb3b;
      padding: 0 2px;
    }

    .pagination {
      display: flex;
      justify-content: center;
      gap: 0.5rem;
      margin-top: 2rem;
    }

    .page-link {
      padding: 0.5rem 1rem;
      background: white;
      border: 1px solid #ddd;
      border-radius: 4px;
      text-decoration: none;
      color: #333;
    }

    .page-link:hover {
      background: #f0f0f0;
    }

    .page-link.active {
      background: #0073aa;
      color: white;
      border-color: #0073aa;
    }

    .page-link.disabled {
      opacity: 0.5;
      pointer-events: none;
    }
  </style>
</head>
<body>

<div class="search-header">
  <h1>🔍 Federated Search</h1>
  <form class="search-form" method="get" action="">
    <input
      type="text"
      name="q"
      class="search-input"
      placeholder="Search across all sites..."
      value="<?php echo htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8'); ?>"
      maxlength="500"
      required
    >
    <button type="submit" class="search-button">Search</button>
  </form>
</div>

<?php if ($error_message): ?>
  <div class="error-message">
    <strong>Error:</strong> <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
  </div>
<?php endif; ?>

<?php if ($search_results): ?>
  <?php
    $num_found = $search_results['response']['numFound'] ?? 0;
    $docs = $search_results['response']['docs'] ?? [];
    $highlighting = $search_results['highlighting'] ?? [];
  ?>

  <div class="search-stats">
    Found <?php echo number_format($num_found); ?> result<?php echo $num_found !== 1 ? 's' : ''; ?>
    for "<?php echo htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8'); ?>"
  </div>

  <?php if (empty($docs)): ?>
    <div class="no-results">
      No results found for "<?php echo htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8'); ?>"
    </div>
  <?php else: ?>
    <?php foreach ($docs as $doc): ?>
      <?php
        $title = $doc['tm_X3b_en_title'][0] ?? $doc['ss_title'] ?? 'Untitled';
        $url = $doc['ss_url'] ?? '#';
        $site_name = $doc['ss_site_id'] ?? 'Unknown Site';

        // Get snippet from highlighting or body
        $snippet = '';
        $is_highlighted = false;
        if (!empty($highlighting[$doc['id']])) {
          $hl = $highlighting[$doc['id']];
          if (!empty($hl['tm_X3b_en_body'])) {
            $snippet = $hl['tm_X3b_en_body'][0];
            $is_highlighted = true;
          } elseif (!empty($hl['tm_X3b_en_title'])) {
            $snippet = $hl['tm_X3b_en_title'][0];
            $is_highlighted = true;
          }
        }
        if (empty($snippet) && !empty($doc['tm_X3b_en_body'][0])) {
          $snippet = substr($doc['tm_X3b_en_body'][0], 0, 200);
          if (strlen($doc['tm_X3b_en_body'][0]) > 200) {
            $snippet .= '...';
          }
        }

        // Sanitize snippet to prevent XSS
        if ($is_highlighted) {
          // For highlighted text, escape everything then restore only <strong> tags
          $snippet = htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');
          $snippet = str_replace(['&lt;strong&gt;', '&lt;/strong&gt;'], ['<strong>', '</strong>'], $snippet);
        } else {
          // For plain text, just escape
          $snippet = htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');
        }
      ?>
      <div class="result">
        <h2 class="result-title">
          <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">
            <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
          </a>
        </h2>
        <div class="result-meta">
          From: <?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php if ($snippet): ?>
          <div class="result-snippet">
            <?php echo $snippet; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php
      $total_pages = ceil($num_found / $results_per_page);
      if ($total_pages > 1):
    ?>
      <div class="pagination">
        <?php if ($current_page > 0): ?>
          <a href="?q=<?php echo urlencode($search_query); ?>&page=<?php echo $current_page - 1; ?>"
             class="page-link">← Previous</a>
        <?php endif; ?>

        <?php
          $start_page = max(0, $current_page - 2);
          $end_page = min($total_pages, $start_page + 5);
          for ($i = $start_page; $i < $end_page; $i++):
        ?>
          <a href="?q=<?php echo urlencode($search_query); ?>&page=<?php echo $i; ?>"
             class="page-link <?php echo $i === $current_page ? 'active' : ''; ?>">
            <?php echo $i + 1; ?>
          </a>
        <?php endfor; ?>

        <?php if ($current_page < $total_pages - 1): ?>
          <a href="?q=<?php echo urlencode($search_query); ?>&page=<?php echo $current_page + 1; ?>"
             class="page-link">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

</body>
</html>
