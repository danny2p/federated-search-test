<?php

if (function_exists('pantheon_get_secret')) {
  $valid_key = pantheon_get_secret('FEDERATED_SEARCH_API_KEY');
} else {
  // Fallback for local development - use environment variable
  $valid_key = getenv('FEDERATED_SEARCH_API_KEY');
}
?>

  <!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Federated Search Widget Example</title>
  <style>
    /* Federated Search Widget Styles */
    .federated-search-widget {
      max-width: 800px;
      margin: 2rem auto;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    .federated-search-form {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 1.5rem;
    }

    .federated-search-input {
      flex: 1;
      padding: 0.75rem 1rem;
      font-size: 1rem;
      border: 2px solid #ccc;
      border-radius: 4px;
      outline: none;
      transition: border-color 0.2s;
    }

    .federated-search-input:focus {
      border-color: #0073aa;
    }

    .federated-search-button {
      padding: 0.75rem 2rem;
      font-size: 1rem;
      background: #0073aa;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      transition: background 0.2s;
    }

    .federated-search-button:hover {
      background: #005177;
    }

    .federated-search-button:disabled {
      background: #ccc;
      cursor: not-allowed;
    }

    .federated-search-filters {
      margin-bottom: 1rem;
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
    }

    .federated-search-filter {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .federated-search-results {
      min-height: 100px;
    }

    .federated-search-loading {
      text-align: center;
      padding: 2rem;
      color: #666;
    }

    .federated-search-error {
      padding: 1rem;
      background: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
      border-radius: 4px;
      margin-bottom: 1rem;
    }

    .federated-search-result {
      padding: 1.5rem;
      margin-bottom: 1rem;
      border: 1px solid #e0e0e0;
      border-radius: 4px;
      transition: box-shadow 0.2s;
    }

    .federated-search-result:hover {
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .federated-search-result-title {
      margin: 0 0 0.5rem 0;
      font-size: 1.25rem;
    }

    .federated-search-result-title a {
      color: #0073aa;
      text-decoration: none;
    }

    .federated-search-result-title a:hover {
      text-decoration: underline;
    }

    .federated-search-result-meta {
      font-size: 0.875rem;
      color: #666;
      margin-bottom: 0.5rem;
    }

    .federated-search-result-snippet {
      color: #333;
      line-height: 1.6;
    }

    .federated-search-result-snippet strong {
      background: #ffeb3b;
      padding: 0 2px;
    }

    .federated-search-pagination {
      display: flex;
      justify-content: center;
      gap: 0.5rem;
      margin-top: 2rem;
    }

    .federated-search-page-button {
      padding: 0.5rem 1rem;
      background: #f0f0f0;
      border: 1px solid #ccc;
      border-radius: 4px;
      cursor: pointer;
      transition: background 0.2s;
    }

    .federated-search-page-button:hover {
      background: #e0e0e0;
    }

    .federated-search-page-button.active {
      background: #0073aa;
      color: white;
      border-color: #0073aa;
    }

    .federated-search-stats {
      color: #666;
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>

<div class="federated-search-widget" id="federatedSearch">
  <!-- Search Form -->
  <form class="federated-search-form" id="searchForm">
    <input
      type="text"
      class="federated-search-input"
      id="searchInput"
      placeholder="Search across all sites..."
      required
    >
    <button type="submit" class="federated-search-button" id="searchButton">
      Search
    </button>
  </form>

  <!-- Filters -->
  <div class="federated-search-filters" id="searchFilters">
    <div class="federated-search-filter">
      <label>
        <input type="radio" name="site_filter" value="" checked> All Sites
      </label>
    </div>
    <!-- Site filters will be populated dynamically -->
  </div>

  <!-- Results Container -->
  <div class="federated-search-results" id="searchResults">
    <!-- Results will be inserted here -->
  </div>
</div>

<script>
/**
 * Federated Search Widget
 *
 * Configuration: Update these values for your setup
 */
const FEDERATED_SEARCH_CONFIG = {
  // Hub URL - Your central search hub
  hubUrl: 'https://dev-your-site-name.pantheonsite.io',

  // API Key - Stored in Pantheon Secrets
  // IMPORTANT: For production, retrieve this from your backend, not client-side
  // This example shows client-side for demonstration only
  apiKey: '<?php echo $valid_key; ?>',

  // Results per page
  resultsPerPage: 10,

  // Enable highlighting
  enableHighlighting: true,

  // Site filter options (leave empty to auto-detect from results)
  sites: [
//   { id: 'marketing', name: 'Marketing Site' },
//    { id: 'blog', name: 'Blog' },
//    { id: 'docs', name: 'Documentation' }
  ]
};

/**
 * Federated Search Widget Class
 */
class FederatedSearchWidget {
  constructor(config) {
    this.config = config;
    this.currentPage = 0;
    this.totalResults = 0;
    this.currentQuery = '';
    this.currentSiteFilter = '';

    this.init();
  }

  init() {
    // Bind form submission
    document.getElementById('searchForm').addEventListener('submit', (e) => {
      e.preventDefault();
      this.search();
    });

    // Bind site filters
    const filtersContainer = document.getElementById('searchFilters');
    this.config.sites.forEach(site => {
      const filterDiv = document.createElement('div');
      filterDiv.className = 'federated-search-filter';
      filterDiv.innerHTML = `
        <label>
          <input type="radio" name="site_filter" value="${site.id}"> ${site.name}
        </label>
      `;
      filtersContainer.appendChild(filterDiv);
    });

    // Bind filter changes
    document.querySelectorAll('input[name="site_filter"]').forEach(radio => {
      radio.addEventListener('change', (e) => {
        this.currentSiteFilter = e.target.value;
        if (this.currentQuery) {
          this.search();
        }
      });
    });

    // Check for query in URL
    const urlParams = new URLSearchParams(window.location.search);
    const query = urlParams.get('q');
    if (query) {
      document.getElementById('searchInput').value = query;
      this.search();
    }
  }

  async search(page = 0) {
    const input = document.getElementById('searchInput');
    const button = document.getElementById('searchButton');
    const resultsContainer = document.getElementById('searchResults');

    this.currentQuery = input.value.trim();
    this.currentPage = page;

    if (!this.currentQuery) {
      return;
    }

    // Update UI
    button.disabled = true;
    button.textContent = 'Searching...';
    resultsContainer.innerHTML = '<div class="federated-search-loading">Searching...</div>';

    try {
      const results = await this.executeSearch();
      this.renderResults(results);
    } catch (error) {
      this.renderError(error.message);
    } finally {
      button.disabled = false;
      button.textContent = 'Search';
    }
  }

  async executeSearch() {
    // Build query parameters
    const params = new URLSearchParams({
      q: this.currentQuery,
      start: this.currentPage * this.config.resultsPerPage,
      rows: this.config.resultsPerPage,
      hl: this.config.enableHighlighting ? 'true' : 'false'
    });

    // Add site filter if selected
    if (this.currentSiteFilter) {
      params.append('site', this.currentSiteFilter);
    }

    // Build URL
    const url = `${this.config.hubUrl}/solr-proxy.php?${params.toString()}`;

    // Execute request
    const response = await fetch(url, {
      method: 'GET',
      headers: {
        'X-Federated-Search-Key': this.config.apiKey
      }
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.error || `HTTP ${response.status}: ${response.statusText}`);
    }

    return await response.json();
  }

  renderResults(data) {
    const resultsContainer = document.getElementById('searchResults');
    const docs = data.response.docs;
    const numFound = data.response.numFound;

    this.totalResults = numFound;

    if (docs.length === 0) {
      resultsContainer.innerHTML = `
        <div class="federated-search-error">
          No results found for "${this.currentQuery}"
        </div>
      `;
      return;
    }

    // Build results HTML
    let html = `
      <div class="federated-search-stats">
        Found ${numFound} results for "${this.currentQuery}"
      </div>
    `;

    docs.forEach(doc => {
      const title = doc.tm_X3b_en_title?.[0] || doc.ss_title || 'Untitled';
      const url = doc.ss_url || '#';
      const siteName = doc.ss_site_id || 'Unknown Site';
      const snippet = this.getSnippet(doc, data.highlighting);

      html += `
        <div class="federated-search-result">
          <h3 class="federated-search-result-title">
            <a href="${url}" target="_blank">${title}</a>
          </h3>
          <div class="federated-search-result-meta">
            From: ${siteName}
          </div>
          <div class="federated-search-result-snippet">
            ${snippet}
          </div>
        </div>
      `;
    });

    // Add pagination
    html += this.renderPagination();

    resultsContainer.innerHTML = html;
  }

  getSnippet(doc, highlighting) {
    // Try to get highlighted snippet
    if (highlighting && highlighting[doc.id]) {
      const hl = highlighting[doc.id];
      if (hl.tm_X3b_en_body || hl.tm_X3b_en_title) {
        return (hl.tm_X3b_en_body || hl.tm_X3b_en_title)[0];
      }
    }

    // Fallback to body or summary
    const body = doc.tm_X3b_en_body?.[0] || doc.tm_summary?.[0] || '';
    return body.substring(0, 200) + (body.length > 200 ? '...' : '');
  }

  renderPagination() {
    const totalPages = Math.ceil(this.totalResults / this.config.resultsPerPage);

    if (totalPages <= 1) {
      return '';
    }

    let html = '<div class="federated-search-pagination">';

    // Previous button
    if (this.currentPage > 0) {
      html += `
        <button class="federated-search-page-button" onclick="federatedSearch.search(${this.currentPage - 1})">
          &laquo; Previous
        </button>
      `;
    }

    // Page numbers (show max 5)
    const startPage = Math.max(0, this.currentPage - 2);
    const endPage = Math.min(totalPages, startPage + 5);

    for (let i = startPage; i < endPage; i++) {
      const activeClass = i === this.currentPage ? 'active' : '';
      html += `
        <button class="federated-search-page-button ${activeClass}" onclick="federatedSearch.search(${i})">
          ${i + 1}
        </button>
      `;
    }

    // Next button
    if (this.currentPage < totalPages - 1) {
      html += `
        <button class="federated-search-page-button" onclick="federatedSearch.search(${this.currentPage + 1})">
          Next &raquo;
        </button>
      `;
    }

    html += '</div>';
    return html;
  }

  renderError(message) {
    const resultsContainer = document.getElementById('searchResults');
    resultsContainer.innerHTML = `
      <div class="federated-search-error">
        <strong>Search Error:</strong> ${message}
      </div>
    `;
  }
}

// Initialize widget
let federatedSearch;
document.addEventListener('DOMContentLoaded', () => {
  federatedSearch = new FederatedSearchWidget(FEDERATED_SEARCH_CONFIG);
});
</script>

</body>
</html>
