# Setting Up Federated Search with Views

This module provides a Search API datasource for federated content, allowing you to build Views-based search pages.

## Quick Setup

### 1. Configure Site Labels (Optional but Recommended)

Go to **Configuration > Search and metadata > Search API > Federated Search Site Labels**

Or visit: `/admin/config/search/federated-search/site-labels`

Add user-friendly labels for your federated sites:
- **Site ID**: `test-term-drupal-11` → **Label**: `Test Term Drupal 11`
- **Site ID**: `drupal-11-downstream-1` → **Label**: `Drupal 11 Downstream`
- etc.

These labels will be used in the search filters.

### 2. Verify the Search API Index

The module automatically creates a "Federated Content" index at `/admin/config/search/search-api`.

Check that it's enabled and using the **Pantheon Search** server.

### 3. Create a View

1. Go to **Structure > Views > Add view**
2. **View settings:**
   - View name: `Federated Search`
   - Show: `Index Federated Content`
   - Create a page: ✓

3. **Page settings:**
   - Page title: `Search Across Sites`
   - Path: `/search` (or your preferred path)
   - Display format: **Unformatted list** of **Fields**
   - Items per page: `20`
   - Use a pager: ✓

4. **Click "Save and edit"**

### 4. Add Fields to the View

Click **Add** next to **Fields** and add:

- **Federated Content: Title**
  - Make the title a link to: URL field
  - Label: Hide

- **Federated Content: Body**
  - Formatter: Trimmed
  - Trim length: 300 characters
  - Label: Hide

- **Federated Content: Site ID**
  - Label: "Source"
  - Rewrite results: Use the site label service (see advanced section)

- **Federated Content: URL**
  - Exclude from display: ✓
  - (Used to link the title)

### 5. Add Search Exposed Filter

1. Click **Add** next to **Filter criteria**
2. Select **Federated Content: Fulltext search**
3. Check **Expose this filter to visitors**
4. Label: `Search`
5. Placeholder text: `Enter search terms...`

### 6. Add Site Filter (Optional)

1. Click **Add** next to **Filter criteria**
2. Select **Federated Content: Site ID**
3. Check **Expose this filter to visitors**
4. Filter type: **Select**
5. Label: `Filter by site`
6. Check **Allow multiple selections**
7. **Advanced:**
   - In the **Expose** section, add an **All** option
   - Default to showing all sites

### 7. Configure Sorting

Add sort criteria:
- **Federated Content: Relevance** (descending) - for search results
- Or **Federated Content: Changed** (descending) - for recent content

### 8. Save and Test

Save your view and visit the page (e.g., `/search`)

## Advanced: Display Site Labels Instead of IDs

To show user-friendly site names instead of IDs in results:

### Method 1: Rewrite Results

In the **Site ID** field settings:
1. Check **Rewrite results**
2. In **Rewrite results** textarea, use:
   ```
   {{ site_id }}
   ```
3. Save

Then create a custom Twig template in your theme:
```twig
{# templates/views-view-field--federated-search--site-id.html.twig #}
{% set site_labels = {
  'test-term-drupal-11': 'Test Term Drupal 11',
  'drupal-11-downstream-1': 'Drupal 11 Downstream',
} %}

{{ site_labels[output] ?? output }}
```

### Method 2: Custom Field Formatter (Recommended)

Create a custom field formatter in your custom module that uses the `federated_search_hub.site_label_manager` service to convert IDs to labels.

## Views Integration Features

### Available Fields:
- **Title** - Content title (full text searchable)
- **Body** - Main content (full text searchable)
- **Summary** - Content summary (full text searchable)
- **URL** - Link to original content
- **Site ID** - Source site identifier
- **Site URL** - Base URL of source site
- **Created** - Creation date
- **Changed** - Last modified date

### Available Filters:
- **Fulltext search** - Search across title, body, summary
- **Site ID** - Filter by specific site(s)
- **Created date** - Filter by creation date
- **Changed date** - Filter by modification date

### Available Sort Criteria:
- **Relevance** - Search relevance score
- **Title** - Alphabetical by title
- **Created** - By creation date
- **Changed** - By last modified date

## Tips

1. **Boost Important Fields**: In the Search API index settings, you can boost the Title field to make title matches rank higher.

2. **Add Facets**: Install the Facets module to add faceted search by site, date, etc.

3. **Custom Styling**: Style your search results with custom CSS classes in the view row style settings.

4. **Contextual Filters**: Add contextual filters to create site-specific search pages.

5. **RSS Feed**: Add a Feed display to create an RSS feed of federated search results.

## Troubleshooting

**No results showing?**
- Check that content has been synced from client sites
- Verify the Search API index is enabled
- Check `/admin/config/search/search-api/index/federated_content`

**Site labels not showing?**
- Configure labels at `/admin/config/search/federated-search/site-labels`
- Clear cache after adding labels

**Search not working?**
- Verify the fulltext search filter is added and exposed
- Check that the Search API server is connected to Solr
