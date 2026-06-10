<?php namespace ProcessWire; 

class SimpleSearch extends WireData implements Module, ConfigurableModule {

    /**
     * Get the module configuration inputfields
     *
     * @return InputfieldWrapper

    * @param string $key The key to fetch the value for.
    * @param string $fallbackValue The fallback value if the language-specific value is not found or is an array.
    * @return string The language-specific value or the fallback value.

    */

    public static function getModuleInfo() {

        return array(
            'title' => 'Simple Search',
            'version' => '1.1.0',
            'summary' => 'A simple search module for ProcessWire.',
            'autoload' => true,
            'singular' => true,
            'icon' => 'search',
            'author' => 'FRE:D',
            'installs' => [], // Optional array of module names that this module should install, if any
            'requires' => [], // Optional array of module names that are required for this module to run, if any
        );

    }

    public function init() {

        // Define the pages to search
        // $this->indexedCategories = ["", "project", "article"];
        
        $this->q = '';
        $this->cat = 0;
        
        $this->results = new WireArray;
        $this->totals = new WireArray;
        $this->labels = new WireArray;

        $this->inputSanitized = $this->sanitizeInput();

        $this->start = (int)$this->limit * ($this->input->pageNum() - 1);

        // Explicitly load the MarkupPagerNav module
        $this->pager = $this->modules->get('MarkupPagerNav');
        
        $this->setIndexedcategories();

        // // Get the indexed templates from the module configuration
        // $indexedTemplates = $this->config->indexedTemplates;

        // // If no indexed templates are selected, fallback to indexing all templates
        // if (empty($indexedTemplates)) {
        //     $indexedTemplates = $this->getDefaultIndexedTemplates();
        // }

        // $this->indexedCategories = $indexedTemplates;
    

    }

    public function ready() {

        $this->addHookAfter("ProcessTemplate::buildEditForm", $this, 'addSimpleSearchCategoryField');

        // Hook to save "seo_rules" field value when the template is saved
        $this->addHookBefore("ProcessTemplate::executeSave", $this, 'saveSimpleSearchCategoryFieldValue');

    }

    public function __uninstall() {
        // // Loop through all the fields created by your module and delete them
        // foreach ($this->fields as $field) {
        //     if ($field->flags && Field::flagSystem) continue; // Skip system fields
        //     if ($field->className === 'FieldtypeSimpleSearch') {
        //         $this->fields->delete($field);
        //     }
        // }
    }

    public function __construct() {
    
        $simpleSearchSettings = wire('modules')->getConfig($this);
        foreach ($simpleSearchSettings as $key => $value) {
            $this->$key = $value;
        }

        $this->limit = (int) $this->limit;
        $this->sublimit = (int) $this->sublimit;
        $this->snippets_amount = max(1, (int) $this->snippets_amount);

        // Optional custom markup (e.g. extended classes); default rendering is on this module
        if (!empty($this->custom_search_results_markup)) {
            $customMarkupFilePath = $this->config->paths->templates . $this->custom_search_results_markup;
            if (file_exists($customMarkupFilePath)) {
                require_once $customMarkupFilePath;
            }
        }
        
    }


    /**
     * @param array $data
     * @return InputfieldWrapper
     */
    public function getModuleConfigInputfields(array $data) {

        $form = $this->wire(new InputfieldWrapper());
        $built = $this->buildIndexedCategoryGroups();
        $groups = $built['groups'];
        $discoveryOrder = $built['discoveryOrder'];

        /** @var InputfieldAsmSelect $field */
        $field = $this->modules->get('InputfieldAsmSelect');
        $field->name = 'category_order';
        $field->label = $this->_('Category section order');
        $field->description = $this->_('Drag to reorder search result sections.');
        $field->notes = $this->_('Templates with the same default-language category label are grouped. The sort order applies to all languages. Frontend section titles use each visitor\'s language.');
        $field->icon = 'sort';
        $field->columnWidth = 100;
        $field->setAsmSelectOption('sortable', true);
        $field->setAsmSelectOption('removeLabel', '');

        foreach ($discoveryOrder as $groupKey) {
            $field->addOption($groupKey, $this->getCategoryGroupOptionLabel($groupKey, $groups[$groupKey]));
        }

        $value = $this->normalizeCategoryOrder($data['category_order'] ?? '');
        if (empty($value)) {
            $value = $discoveryOrder;
        } else {
            foreach ($discoveryOrder as $groupKey) {
                if (!in_array($groupKey, $value, true)) {
                    $value[] = $groupKey;
                }
            }
            $value = array_values(array_filter($value, function($groupKey) use ($groups) {
                return isset($groups[$groupKey]);
            }));
        }

        $field->value = $value;
        $form->add($field);

        return $form;

    }


    public function addSimpleSearchCategoryField(HookEvent $event) {
        $languages = $this->wire('languages');
        $template = $event->arguments[0];
        $form = $event->return;
    
        $field = $this->modules->get("InputfieldText");
        $field->attr('id+name', 'simplesearch_category'); 
        $field->attr('value', $template->simplesearch_category);
        if ($languages) {
            $field->useLanguages = true;
            foreach ($languages as $language) {
                $field->set('value' . $language->id, $template->get("simplesearch_category__{$language->id}"));
            }
        }
        $field->label = $this->_('SimpleSearch Category');
        $field->description = $this->_('Enter the SimpleSearch category label for this template. If empty, pages using this template will NOT be indexed. Templates that share the same default-language label are grouped into one results section. Section order is configured in the SimpleSearch module settings.');
        $field->notes = $this->_('tipp: use plural');

        // $form->insertAfter($field, $form->tags);

        // Find the "label" field in the form
        $labelField = $form->getChildByName('templateLabel');

        // Insert the "simplesearch_category" field after the "label" field
        $form->insertAfter($field, $labelField);

        // $this->message($form->simplesearch_category);
        
        $event->return = $form;
    }
    

    public function saveSimpleSearchCategoryFieldValue(HookEvent $event) {
        $template = $this->templates->get($this->input->post->id);
        $template->set('simplesearch_category', $this->input->post->simplesearch_category);
    
        $languages = $this->wire('languages');
        if ($languages) {
            foreach ($languages as $language) {
                $template->set("simplesearch_category__{$language->id}", $this->input->post->{"simplesearch_category__$language->id"});
            }
        }
    }
        

    protected function setIndexedcategories() {

        $built = $this->buildIndexedCategoryGroups();
        $sortedGroups = $this->sortCategoryGroups(
            $built['groups'],
            $built['discoveryOrder'],
            $this->getConfiguredCategoryOrder()
        );

        $indexedCategories = new WireArray;
        foreach ($sortedGroups as $group) {
            $indexedCategories->add($group);
        }

        $indexedCategories->prepend('');
        $this->indexedCategories = $indexedCategories;

    }


    /**
     * Build category groups from indexed templates.
     *
     * Groups by default-language simplesearch_category (stable key). Display labels
     * use the active language when results are rendered.
     *
     * @return array{groups: array<string, array>, discoveryOrder: array<int, string>}
     */
    public function buildIndexedCategoryGroups(): array {

        $groups = [];
        $discoveryOrder = [];

        foreach ($this->templates as $temp) {
            if ($temp->simplesearch_category === '') continue;

            $groupKey = $this->getCategoryGroupKey($temp);
            if ($groupKey === '') continue;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'label' => $this->getTemplateCategoryLabel($temp),
                    'templates' => [],
                ];
                $discoveryOrder[] = $groupKey;
            }

            $groups[$groupKey]['templates'][] = $temp->name;
        }

        return [
            'groups' => $groups,
            'discoveryOrder' => $discoveryOrder,
        ];

    }


    /** Stable group key from the default-language category label. */
    protected function getCategoryGroupKey(Template $template): string {
        return mb_strtolower(trim((string) $template->simplesearch_category));
    }


    /** @return array<int, string> */
    protected function getConfiguredCategoryOrder(): array {
        return $this->normalizeCategoryOrder($this->category_order ?? '');
    }


    /**
     * @param array<int, string>|string $order
     * @return array<int, string>
     */
    protected function normalizeCategoryOrder($order): array {

        if (is_array($order)) {
            $keys = array_map(function($key) {
                return mb_strtolower(trim((string) $key));
            }, $order);
        } else {
            $raw = trim((string) $order);
            if ($raw === '') return [];

            $keys = preg_split('/\R/u', $raw) ?: [];
            $keys = array_map(function($key) {
                return mb_strtolower(trim((string) $key));
            }, $keys);
        }

        return array_values(array_filter($keys, function($key) {
            return $key !== '';
        }));

    }


    protected function getCategoryGroupOptionLabel(string $groupKey, array $group): string {

        $templateNames = $group['templates'];
        $template = $this->templates->get($templateNames[0] ?? '');

        if (!$template) {
            return $groupKey . ' (' . implode(', ', $templateNames) . ')';
        }

        $label = trim((string) $template->simplesearch_category);
        if ($label === '') $label = $groupKey;

        return $label . ' (' . implode(', ', $templateNames) . ')';

    }


    /**
     * @param array<string, array> $groups
     * @param array<int, string> $discoveryOrder
     * @param array<int, string> $orderedKeys
     * @return array<int, array>
     */
    protected function sortCategoryGroups(array $groups, array $discoveryOrder, array $orderedKeys): array {

        $appendUnknown = function(array $orderKeys) use ($groups, $discoveryOrder): array {
            $result = [];
            $used = [];

            foreach ($orderKeys as $key) {
                if (!isset($groups[$key]) || isset($used[$key])) continue;
                $result[] = $groups[$key];
                $used[$key] = true;
            }

            foreach ($discoveryOrder as $key) {
                if (isset($used[$key])) continue;
                $result[] = $groups[$key];
            }

            return $result;
        };

        if (empty($orderedKeys)) {
            return $appendUnknown([]);
        }

        return $appendUnknown($orderedKeys);

    }


    /** Localized SimpleSearch category label for a template. */
    protected function getTemplateCategoryLabel(Template $template): string {
        $property = $this->getLanguageString('simplesearch_category', '__');
        $label = trim((string) $template->get($property));

        if ($label === '') {
            $label = trim((string) $template->simplesearch_category);
        }

        return $label;
    }


    /** @return array<int, string> */
    protected function getCategoryTemplateNames($category): array {
        if (is_array($category) && !empty($category['templates'])) {
            return $category['templates'];
        }

        return [(string) $category];
    }


    protected function getCategoryDisplayLabel($category): string {
        if (is_array($category) && !empty($category['label'])) {
            return $category['label'];
        }

        $string = $this->getLanguageString('simplesearch_category', '__');
        $template = $this->templates->get((string) $category);

        return $template ? (string) $template->get($string) : '';
    }


    protected function updateStart() {

        $start = (int)$this->limit * ($this->input->pageNum() - 1);
        return $start;
    
    }


    protected function sanitizeInput() {

        // Sanitize the search term input
        $sanitizer = $this->wire('sanitizer');
        $input = $this->wire('input');
    
        // // Sanitize and store the search term 'q'
        // if (isset($input->get->q)) {
        //     $q = $sanitizer->text($input->get->q);
        //     $q = preg_replace('/[^\p{L}\p{N}_\s]/u', '', $q);
        //     $this->q = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
        // } else {
        //     $this->q = null; // or $this->q = ''; if you prefer an empty string
        // }

        if (isset($input->get->q)) {
            $q = $sanitizer->text($input->get->q);
            // Allow letters, numbers, underscores, spaces, and dots
            $q = preg_replace('/[^\p{L}\p{N}_\s.]/u', '', $q);
            $this->q = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
        } else {
            $this->q = null; // or $this->q = ''; if you prefer an empty string
        }
        
    
        // Sanitize and store the category 'cat'
        if (isset($input->get->cat)) {
            $this->cat = $sanitizer->int($input->get->cat);
        } else {
            $this->cat = 0; // or any other default value you want
        }

    }

    
    public function handleSearch() {

        // Check if the search form was submitted (i.e., input variable exists)

        if ($this->q) {

            $this->allResultsLabel = $this->checkAndGetLanguageValue('all_entries_label', '__');
        
            $indexedCategories = $this->indexedCategories;
            
            $allTotals = 0;

            foreach ($indexedCategories as $cat => $category) {

                if ($cat == 0) continue;

                // Pass the sanitized input to createSelector() to get the selector string.
                $selector = $this->createSelector($this->q, $category);
                $unfilteredMatches = $this->pages("$selector, start=0, limit=99999");

                $matches = new PageArray;

                foreach ($unfilteredMatches as $match) {
                    $snippets = $this->checkAndRenderSnippets($match);
                    if (!$snippets) continue;
                    $match->set('snippets', $snippets);
                    $matches->add($match);
                }

                $matches->sort("-date_modification");

                // Calculate the total matches and the start index for the current page
                $total = count($matches);

                $this->results->set($cat, $matches);
                $this->totals->set($cat, $total);

                $this->labels->set($cat, $this->getCategoryDisplayLabel($category));

                $allTotals += $total;

            }

            $this->results->prepend('');
            $this->totals->prepend($allTotals);
            $this->labels->prepend($this->allResultsLabel);

            // Update the total count for all results
            $this->totals->set(0, $allTotals);

        } 
        
        // echo 'Memory Usage: ' . (memory_get_usage(true) / 1024 / 1024) . ' MB' . PHP_EOL;

        // $startTime = microtime(true);

        // // Your script here
        
        // $endTime = microtime(true);
        // $executionTime = $endTime - $startTime;
        
        // echo 'Execution Time: ' . $executionTime . ' seconds' . PHP_EOL;
        
    }
    
        
    protected function createSelector($q, $category) {

        $templateNames = $this->getCategoryTemplateNames($category);
        $selector = 'template=' . implode('|', $templateNames);
        $search_operator = $this->search_operator;

        $fields = $this->getUniqueFieldsFromTemplates($templateNames);

        if (count($fields) === 0) {
            return $selector;
        }

        $selector .= ', ' . implode('|', $fields) . "$search_operator$q";

        return $selector;

    }


    /** @param array<int, string> $templateNames */
    protected function getUniqueFieldsFromTemplates(array $templateNames): array {
        $fields = [];

        foreach ($templateNames as $templateName) {
            foreach ($this->getUniqueFieldsFromTemplate($templateName) as $fieldName) {
                $fields[$fieldName] = $fieldName;
            }
        }

        return array_values($fields);
    }


    // Helper method to extract unique fields from an array of templates
    protected function getUniqueFieldsFromTemplate($category) {

        $fields = [];
        $allowedFieldTypes = [
            "FieldtypeTextLanguage",
            "FieldtypeTextareaLanguage",
            "FieldtypePageTitleLanguage",
            "FieldtypeText",
            "FieldtypeTextarea",
            "FieldtypePageTitle",
        ];

        $template = $this->templates->get((string) $category);
        if (!$template) {
            return $fields;
        }

        foreach ($template->fields as $field) {

            // if (strpos($field->type, "Text") == false && strpos($field->type, "Title") == false) continue;
            if (!in_array($field->type, $allowedFieldTypes)) { continue; }
            $fields[] = $field->name; // Store the name of the field in the $fields array
        }

        return array_unique($fields);

    }

    
    public function renderMarkupForSearchCategory($matches) {

        if (!$matches->count()) return '';

        $first = $matches->first();
        if ($first && method_exists($first, 'renderLayout_Search')) {
            return $first->renderLayout_Search($matches);
        }

        return $this->render_DefaultMarkup($matches);
    }


    protected function render_DefaultMarkup(PageArray $matches) {

        $html = '';

        foreach ($matches as $match) {
            $html .= '<li class="simplesearch-result">';
            $html .= '<a href="' . $match->url . '">' . $this->sanitizer->entities($match->title) . '</a>';
            if ($match->snippets) {
                $html .= '<div class="simplesearch-snippets">' . $match->snippets . '</div>';
            }
            $html .= '</li>';
        }

        return $html;
    }


    protected function checkAndRenderSnippets($match) {

        $searchTerm = $this->q;
        $uniqueFields = $this->getUniqueFieldsFromTemplate($match->template);
        $maxSnippets = max(1, (int) $this->snippets_amount);
        $snippetMarkups = '';
        $snippetCount = 0;

        foreach ($uniqueFields as $field) {
            if ($snippetCount >= $maxSnippets) break;

            $fieldValue = $match->$field;
            $s = $this->buildSnippets($fieldValue, $searchTerm, 25, 25);
            if ($s) {
                $snippetMarkups .= $s;
                $snippetCount++;
            }
        }

        return $snippetMarkups !== '' ? $snippetMarkups : false;
    }
            

    protected function buildSnippets($fieldValue, $searchTerm, $snipStart = 25, $snipEnd = 25) {
        $strippedFieldValue = strip_tags($fieldValue);
        // Find the position of the search term in the field value
        $position = stripos($strippedFieldValue, $searchTerm);
    
        // If the search term is not found, return an empty string
        if ($position === false) {
            return false;
        }
    
        // Calculate the start and end positions for the snippet
        $startPos = max(0, $position - $snipStart);
        $endPos = min(strlen($strippedFieldValue), $position + strlen($searchTerm) + $snipEnd);

        // Include the 25 characters before and after the search term as $startChars and $endChars
        $startChars = max(0, $position - $snipStart);
        $endChars = min(strlen($strippedFieldValue) - ($position + strlen($searchTerm)), $snipEnd);

        // Extract the characters before and after the snippet
        $startChars = substr($strippedFieldValue, $startChars, $position - $startChars);
        $endChars = substr($strippedFieldValue, $position + strlen($searchTerm), $endChars);

        // Surround the snippet with additional characters (e.g., <strong>)
        $snippet = $startChars . '<strong>' . $searchTerm . '</strong>' . $endChars;

        return ' … ' . $snippet . ' … ';

    }
        

    
    // // Custom function to replace search term with highlighted version while preserving case
    // function replaceWithHighlight($content, $searchTerm) {
    //     $highlightedTerm = '<strong>' . $searchTerm . '</strong';
    //     $pattern = '/\b' . preg_quote($searchTerm, "/") . '\b/i';

    //     return preg_replace($pattern, $highlightedTerm, $content);
    // }


    // Helper method to highlight the search term
    protected function highlightSearchTerm($snippet, $searchTerm) {
        return $this->replaceWithHighlight($snippet, $searchTerm);
    }
        

    // Custom function to replace search term with highlighted version while preserving case
    function replaceWithHighlight($content, $searchTerm) {
        $pattern = '/\b' . preg_quote($searchTerm, "/") . '\b/i';
        $replacement = '<strong>$0</strong>';
        return preg_replace($pattern, $replacement, $content);
    }

    
    protected function checkAndGetLanguageValue(string $key, string $x='') {
        $fieldNameString = $this->getLanguageString($key, $x);
        return $this->$fieldNameString;
    }

    protected function getLanguageString(string $key, string $x='') {
        $language = $this->user->language;
        if ($language->name !== 'default') {
            $string = $key.$x.$language->id;
            return $string;
        } 
        return $key;
    }
        

    public function render_CriteriaMarkup() {

        $searchCriteriaFormat = $this->checkAndGetLanguageValue('search_criteria', '__');
    
        if (!$this->q) return;
    
        $html = $searchCriteriaFormat;
    
        $searchQuery = $this->q;
    
        $html = str_replace('{template}', $this->labels->eq($this->cat), $html);
        $html = str_replace('{q}', $searchQuery, $html);
    
        return $html;

    }


    public function render_OverviewMarkup() {

        if (!$this->q) return;

        $html = '';

        // overview :D

        $cat = $this->cat;
        $allTotals = $this->totals->eq(0);

        if ($allTotals > 0) {
            if ($cat == 0 || !$cat) {
                $html .= '<strong>' . $this->allResultsLabel . ' (' . $allTotals . '), </strong>';
            } else {
                $html .= '<a class="colorlink" href="./?q=' . $this->q . '">' . $this->allResultsLabel . ' (' . $allTotals . '), </a>';
            }
        }

        // still overview :D

        foreach ($this->indexedCategories as $key => $content) {
            if ($key == 0) continue;

            $total = $this->totals->eq($key);

            if ($total < 1) {
                $html .= '<strong class="grey">' . $this->labels->eq($key) . ' (' . $total . '), </strong>';
            } else {
                if ($cat == $key) {
                    $html .= '<strong>' . $this->labels->eq($key) . ' (' . $total . '), </strong>';
                } else {
                    // $html .= '<a class="colorlink" href="./?q=' . $this->q . '&cat=' . $key . '">' . $this->labels->eq($key) . ' (' . $total . '), </a>';
                    $html .= '<a class="colorlink" href="./?q=' . $this->q . '&cat=' . $key . '">' . $this->labels->eq($key) . ' (' . $total . '), </a>';
                }
            }

        }

        $html .= '<hr class="mt-6 border-gray-200">';

        return $html;

    }


    public function render_ResultsMarkup() {

        if (!$this->q) return;
        
        $allTotals = $this->totals->eq(0);
        $cat = $this->cat;

        // results :D

        $html = '';

        if ($cat == 0) {
            foreach ($this->results as $key => $matches) {
                if ($key == 0) continue; 

                $total = $this->totals->eq($key); 

                if ($total < 1) continue;

                $limit = $this->sublimit;
                $matches->filter("limit=$limit");

                $html .= '<section>';
                $html .= '  <h3><a class="colorlink" href="./?q=' . $this->q . '&cat=' . $key . '">' . $this->labels->eq($key) . ' (' . $total . ')</a></h3>';                
                $html .= '  <ul class="">';
                $html .= $this->renderMarkupForSearchCategory($matches);
                $html .= '  </ul>';

                if ($total > $this->sublimit) {
                    $html .= '<p class="py-12 text-2xl"><a class="colorlink" href="./?q=' . $this->q . '&cat=' . $key . '">mehr…</a></p>';
                }

                $html .= '</section>';

            }
        } else {
            
            $total = $this->totals->eq($cat); 
            $matches = $this->results->eq($cat);
            $start = $this->updateStart();
            $limit = (int)$this->limit;
            $pagMatches = $matches->find("start=$start, limit=$limit");
            
            $html .= '<section>';            
            $html .= '  <h3><strong>' . $this->labels->eq($cat) . ' (' . $total . ')</strong></h3>';
            $html .= '  <ul class="nostyle">';
            $html .= $this->renderMarkupForSearchCategory($pagMatches);
            $html .= '  </ul>';
            $html .= '</section>';
            
        }
        
        return $html;

    }


    public function render_PaginationString() {

        if (!$this->q) return;

        $pagination_string_entries = $this->checkAndGetLanguageValue('pagination_string_entries', '__');

        // pagination string :D

        $cat = $this->cat;

        $html = '';

        if ($cat > 0) {

            $matches = $this->results->eq($cat);
            $start = $this->updateStart();
            $limit = (int)$this->limit;
            $pagMatches = $matches->find("start=$start, limit=$limit");

            if ($pagMatches->count) {
                $html .= '<span class="grey">' . $pagMatches->getPaginationString(array(
                    'label' => $pagination_string_entries,
                    'zeroLabel' => '0 '.$pagination_string_entries, // 3.0.127+ only
                    'usePageNum' => false,
                    'count' => $pagMatches->count(),
                    'start' => $pagMatches->getStart(),
                    'limit' => $pagMatches->getLimit(),
                    'total' => $this->totals->eq($cat)
                    // 'count' => $pagMatches->count(),
                    // 'start' => $this->updateStart(),
                    // 'limit' => $this->limit,
                    // 'total' => $this->totals->eq($cat)
                )) . '</span>';
            }
        }

        return $html;        
        
    }

    public function render_Filters($url = './') {

        $html = '<form action="'.$url.'" method="get">';
        $qValue = isset($this->q) ? htmlentities($this->q) : '';
        $html .= '<label for="q">Search:</label>';
        $html .= '<input type="text" id="q" name="q" value="' . $qValue . '">';
        $html .= '<input type="submit" value="Search">';
        $html .= '</form>';
    
        return $html;

    }

    public function returnQ() {

        $qValue = isset($this->q) ? htmlentities($this->q) : '';
    
        return $qValue;

    }

    public function __render_PaginationMarkup() {
    
        $html = '';

        // Check if we need to render pagination links
        if ($this->cat > 0 && $this->q != '') {

            $pager = $this->wire('modules')->get('MarkupPagerNav');

            $matches = $this->results->eq($this->cat);
            $start = $this->updateStart();
            $limit = (int) $this->limit;
            $matches->setStart($start);
            $matches->setLimit($limit);
            $this->matches = $matches;

            $q = $this->sanitizer->entities($this->q);
            $cat = (int) $this->cat;

            $html = '<section>';
			$html .= '<div class="px-4 py-3 sm:px-6">';
			$html .= '<div class="flex justify-center p-4 sm:flex sm:flex-1 sm:items-center sm:justify-between">';
			$html .= '<div class="w-full text-center">';
			$html .= '<nav class="isolate inline-flex space-x-px rounded-card shadow-sm" aria-label="Pagination">';
			

            $options = array(
                'numPageLinks' => 5,
                'listClass' => 'flex items-center justify-between',
                'linkMarkup' => "<a class='align-baseline font-cf-regular relative inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 border border-gray-300 bg-white' href='{url}?q={$q}&cat={$cat}'>{out}</a>",
                'currentItemClass' => 'border border-teal-600 relative z-10 inline-flex items-center px-3 py-2 text-sm font-medium text-white bg-teal-600 hover:bg-teal-800 focus:z-20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600',
                'itemMarkup' => '<li class="align-baseline {class} h-auto text-cc1 hover:bg-cc1">{out}</li>',
                'currentLinkMarkup' => "<a class='align-baseline font-cf-regular text-white'>{out}</a>",
                'separatorItemClass' => 'align-baseline font-cf-regular text-lg px-3 relative inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 focus:z-20 focus:outline-offset-0 border border-gray-300 bg-white',
                'nextItemClass' => '',
                'previousItemClass' => '',
                'lastItemClass' => '',
                'firstItemClass' => '',
                'nextItemLabel' => '>',
                'previousItemLabel' => '<',
                'separatorItemLabel' => '<span>…</span>',
            );            
    
			$html .= $pager->render($matches, $options);
			
			$html .= '</nav>';
			$html .= '</div>';
			$html .= '</div>';
			$html .= '</div>';
			$html .= '</section>';

        } 
    
        // Return the stored HTML
        return $html;

    }

        
}