<?php
/**
 * Facet-driven channel provider.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind
 * @package  Channels
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
namespace VuFind\ChannelProvider;
use VuFind\RecordDriver\AbstractBase as RecordDriver;
use VuFind\Search\Base\Params, VuFind\Search\Base\Results;
use VuFind\Search\Results\PluginManager as ResultsManager;

/**
 * Facet-driven channel provider.
 *
 * @category VuFind
 * @package  Channels
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
class Facets extends AbstractChannelProvider
{
    /**
     * Facet fields to use (field name => description).
     *
     * @var array
     */
    protected $fields = [
        'topic_facet' => 'Topic',
        'author_facet' => 'Author',
    ];

    /**
     * Maximum number of different fields to suggest in the channel list.
     *
     * @var int
     */
    protected $maxFieldsToSuggest = 2;

    /**
     * Maximum number of values to suggest per field.
     *
     * @var int
     */
    protected $maxValuesToSuggestPerField = 2;

    /**
     * Search results manager.
     *
     * @var ResultsManager
     */
    protected $resultsManager;

    /**
     * Constructor
     *
     * @param ResultsManager $rm Results manager
     */
    public function __construct(ResultsManager $rm)
    {
        $this->resultsManager = $rm;
    }

    /**
     * Hook to configure search parameters before executing search.
     *
     * @param Params $params Search parameters to adjust
     *
     * @return void
     */
    public function configureSearchParams(Params $params)
    {
        foreach ($this->fields as $field => $desc) {
            $params->addFacet($field, $desc);
        }
    }

    /**
     * Return channel information derived from a record driver object.
     *
     * @param RecordDriver $driver Record driver
     *
     * @return array
     */
    public function getFromRecord(RecordDriver $driver)
    {
        $channels = [];
        $fieldCount = 0;
        $data = $driver->getRawData();
        foreach (array_keys($this->fields) as $field) {
            if (!isset($data[$field])) {
                continue;
            }
            $currentValueCount = 0;
            foreach ($data[$field] as $value) {
                $results = $this->resultsManager
                    ->get($driver->getSourceIdentifier());
                $current = [
                    'value' => $value,
                    'displayText' => $value,
                ];
                $channel = $this
                    ->buildChannelFromFacet($results, $field, $current);
                if (count($channel['contents']) > 0) {
                    $channels[] = $channel;
                    $currentValueCount++;
                }
                if ($currentValueCount >= $this->maxValuesToSuggestPerField) {
                    break;
                }
            }
            if ($currentValueCount > 0) {
                $fieldCount++;
            }
            if ($fieldCount >= $this->maxFieldsToSuggest) {
                break;
            }
        }
        return $channels;
    }

    /**
     * Return channel information derived from a search results object.
     *
     * @param Results $results Search results
     *
     * @return array
     */
    public function getFromSearch(Results $results)
    {
        $channels = [];
        $fieldCount = 0;
        $facetList = $results->getFacetList();
        foreach (array_keys($this->fields) as $field) {
            if (!isset($facetList[$field])) {
                continue;
            }
            $currentValueCount = 0;
            foreach ($facetList[$field]['list'] as $current) {
                if (!$current['isApplied']) {
                    $channel = $this
                        ->buildChannelFromFacet($results, $field, $current);
                    if (count($channel['contents']) > 0) {
                        $channels[] = $channel;
                        $currentValueCount++;
                    }
                }
                if ($currentValueCount >= $this->maxValuesToSuggestPerField) {
                    break;
                }
            }
            if ($currentValueCount > 0) {
                $fieldCount++;
            }
            if ($fieldCount >= $this->maxFieldsToSuggest) {
                break;
            }
        }
        return $channels;
    }

    /**
     * Add a new filter to an existing search results object to populate a
     * channel.
     *
     * @param Results $results Results object
     * @param string  $field   Field name (for filter)
     * @param array   $value   Field value information (for filter)
     *
     * @return array
     */
    protected function buildChannelFromFacet(Results $results, $field, $value)
    {
        $newResults = clone($results);
        $params = $newResults->getParams();

        // Determine the filter for the current channel, and add it:
        $filter = "$field:{$value['value']}";
        $params->addFilter($filter);

        // Run the search and convert the results into a channel:
        $newResults->performAndProcessSearch();
        return [
            'title' => "{$this->fields[$field]}: {$value['displayText']}",
            'contents' => $this->summarizeRecordDrivers($newResults->getResults())
        ];
    }
}