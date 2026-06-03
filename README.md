# SimpleSearch Module for ProcessWire

![ProcessWire Version Compatibility](https://img.shields.io/badge/ProcessWire-3.x-1abc9c.svg)

A simple search module for ProcessWire.

## Introduction

SimpleSearch is a ProcessWire module that provides basic search functionality for your ProcessWire-powered website. It allows users to search for content based on specified criteria and presents the results in an organized manner.

## Features

- Basic search functionality.
- Category-based searching.
- Pagination for search results.
- Customizable search criteria and result markup.
- Works on monolingual sites (standard Text/Textarea/PageTitle fields) and multilingual sites (Language fieldtypes). LanguageSupport is optional.

## Installation

1. Download the latest release from the [GitHub releases page](https://github.com/dtjngl/SimpleSearch/releases).
2. Extract the contents of the ZIP archive to your ProcessWire site's `site/modules/` directory.
3. Log in to your ProcessWire admin and navigate to the Modules area.
4. Find "SimpleSearch" in the list and click "Install."

## Configuration

Adjust the module settings in the ProcessWire admin:

1. Log in to your ProcessWire admin.
2. Navigate to Setup > Modules > Site > SimpleSearch.
3. Modify the settings under the "Configuration" tab according to your requirements.

### Search Template Usage

1. Create a template to handle search requests, for example, `search.php`.
2. Use the following code in your search template to integrate SimpleSearch:

```php
<?php namespace ProcessWire; 

$simpleSearch = $modules->get('SimpleSearch');
$simpleSearch->handleSearch();

?>

<!-- Your HTML and search form code here -->

<div id="criteria" class="text-gray-400 py-2 inline-block">
    <?= $simpleSearch->render_CriteriaMarkup(); ?>
</div>

<div id="paginationstring">
    <!-- <?php echo $simpleSearch->renderPaginationString(); ?> -->
</div>

<div id="overview">
    <?= $simpleSearch->render_OverviewMarkup(); ?>
</div>

<div id="search-results">
    <ul>
        <?= $simpleSearch->render_ResultsMarkup(); ?>
    </ul>
</div>

<div id="pagination">
    <?php echo $simpleSearch->__render_PaginationMarkup(); ?>
</div>
