<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Piwik\Archive\Parameters;

use Piwik\ArchiveProcessor\Rules;
use Piwik\DataAccess\ArchiveSelector;
use Piwik\Period\Range;

/**
 * The **Archive** class is used to query cached analytics statistics
 * (termed "archive data").
 * 
 * You can use **Archive** instances to get data that was archived for one or more sites,
 * for one or more periods and one optional segment.
 * 
 * If archive data is not found, this class will initiate the archiving process. [1](#footnote-1)
 * 
 * **Archive** instances must be created using the {@link build()} factory method;
 * they cannot be constructed.
 * 
 * You can search for metrics (such as `nb_visits`) using the {@link getNumeric()} and
 * {@link getDataTableFromNumeric()} methods. You can search for
 * reports using the {@link getBlob()}, {@link getDataTable()} and {@link getDataTableExpanded()} methods.
 * 
 * If you're creating an API that returns report data, you may want to use the
 * {@link getDataTableFromArchive()} helper function.
 * 
 * ### Learn more
 * 
 * Learn more about _archiving_ [here](/guides/all-about-analytics-data).
 * 
 * ### Limitations
 * 
 * - You cannot get data for multiple range periods in a single query.
 * - You cannot get data for periods of different types in a single query.
 * 
 * ### Examples
 * 
 * **_Querying metrics for an API method_**
 * 
 *     // one site and one period
 *     $archive = Archive::build($idSite = 1, $period = 'week', $date = '2013-03-08');
 *     return $archive->getDataTableFromNumeric(array('nb_visits', 'nb_actions'));
 *     
 *     // all sites and multiple dates
 *     $archive = Archive::build($idSite = 'all', $period = 'month', $date = '2013-01-02,2013-03-08');
 *     return $archive->getDataTableFromNumeric(array('nb_visits', 'nb_actions'));
 * 
 * **_Querying and using metrics immediately_**
 * 
 *     // one site and one period
 *     $archive = Archive::build($idSite = 1, $period = 'week', $date = '2013-03-08');
 *     $data = $archive->getNumeric(array('nb_visits', 'nb_actions'));
 *     
 *     $visits = $data['nb_visits'];
 *     $actions = $data['nb_actions'];
 *
 *     // ... do something w/ metric data ...
 *
 *     // multiple sites and multiple dates
 *     $archive = Archive::build($idSite = '1,2,3', $period = 'month', $date = '2013-01-02,2013-03-08');
 *     $data = $archive->getNumeric('nb_visits');
 *     
 *     $janSite1Visits = $data['1']['2013-01-01,2013-01-31']['nb_visits'];
 *     $febSite1Visits = $data['1']['2013-02-01,2013-02-28']['nb_visits'];
 *     // ... etc.
 *     
 * **_Querying for reports_**
 * 
 *     $archive = Archive::build($idSite = 1, $period = 'week', $date = '2013-03-08');
 *     $dataTable = $archive->getDataTable('MyPlugin_MyReport');
 *     // ... manipulate $dataTable ...
 *     return $dataTable;
 * 
 * **_Querying a report for an API method_**
 * 
 *     public function getMyReport($idSite, $period, $date, $segment = false, $expanded = false)
 *     {
 *         $dataTable = Archive::getDataTableFromArchive('MyPlugin_MyReport', $idSite, $period, $date, $segment, $expanded);
 *         $dataTable->queueFilter('ReplaceColumnNames');
 *         return $dataTable;
 *     }
 * 
 * **_Querying data for multiple range periods_**
 * 
 *     // get data for first range
 *     $archive = Archive::build($idSite = 1, $period = 'range', $date = '2013-03-08,2013-03-12');
 *     $dataTable = $archive->getDataTableFromNumeric(array('nb_visits', 'nb_actions'));
 *     
 *     // get data for second range
 *     $archive = Archive::build($idSite = 1, $period = 'range', $date = '2013-03-15,2013-03-20');
 *     $dataTable = $archive->getDataTableFromNumeric(array('nb_visits', 'nb_actions'));
 * 
 * <a name="footnote-1"></a>
 * [1]: The archiving process will not be launched if browser archiving is disabled
 *      and the current request came from a browser (and not the **archive.php** cron
 *      script).
 *
 *
 * @api
 */
class Archive
{
    const REQUEST_ALL_WEBSITES_FLAG = 'all';
    const ARCHIVE_ALL_PLUGINS_FLAG = 'all';
    const ID_SUBTABLE_LOAD_ALL_SUBTABLES = 'all';

    /**
     * List of archive IDs for the site, periods and segment we are querying with.
     * Archive IDs are indexed by done flag and period, ie:
     *
     * array(
     *     'done.Referrers' => array(
     *         '2010-01-01' => 1,
     *         '2010-01-02' => 2,
     *     ),
     *     'done.VisitsSummary' => array(
     *         '2010-01-01' => 3,
     *         '2010-01-02' => 4,
     *     ),
     * )
     *
     * or,
     *
     * array(
     *     'done.all' => array(
     *         '2010-01-01' => 1,
     *         '2010-01-02' => 2
     *     )
     * )
     *
     * @var array
     */
    private $idarchives = array();

    /**
     * If set to true, the result of all get functions (ie, getNumeric, getBlob, etc.)
     * will be indexed by the site ID, even if we're only querying data for one site.
     *
     * @var bool
     */
    private $forceIndexedBySite;

    /**
     * If set to true, the result of all get functions (ie, getNumeric, getBlob, etc.)
     * will be indexed by the period, even if we're only querying data for one period.
     *
     * @var bool
     */
    private $forceIndexedByDate;

    /**
     * @var Parameters
     */
    private $params;

    /**
     * @param Parameters $params
     * @param bool $forceIndexedBySite Whether to force index the result of a query by site ID.
     * @param bool $forceIndexedByDate Whether to force index the result of a query by period.
     */
    protected function __construct(Parameters $params, $forceIndexedBySite = false,
                                   $forceIndexedByDate = false)
    {
        $this->params = $params;
        $this->forceIndexedBySite = $forceIndexedBySite;
        $this->forceIndexedByDate = $forceIndexedByDate;
    }

    /**
     * Returns a new Archive instance that will query archive data for the given set of
     * sites and periods, using an optional Segment.
     *
     * This method uses data that is found in query parameters, so the parameters to this
     * function can be string values.
     *
     * If you want to create an Archive instance with an array of Period instances, use
     * {@link Archive::factory()}.
     *
     * @param string|int|array $idSites A single ID (eg, `'1'`), multiple IDs (eg, `'1,2,3'` or `array(1, 2, 3)`),
     *                                  or `'all'`.
     * @param string $period 'day', `'week'`, `'month'`, `'year'` or `'range'`
     * @param Date|string $strDate 'YYYY-MM-DD', magic keywords (ie, 'today'; {@link Date::factory()}
     *                             or date range (ie, 'YYYY-MM-DD,YYYY-MM-DD').
     * @param bool|false|string $segment Segment definition or false if no segment should be used. {@link Piwik\Segment}
     * @param bool|false|string $_restrictSitesToLogin Used only when running as a scheduled task.
     * @param bool $skipAggregationOfSubTables Whether the archive, when it is processed, should also aggregate all sub-tables
     * @return Archive
     */
    public static function build($idSites, $period, $strDate, $segment = false, $_restrictSitesToLogin = false, $skipAggregationOfSubTables = false)
    {
        $websiteIds = Site::getIdSitesFromIdSitesString($idSites, $_restrictSitesToLogin);

        if (Period::isMultiplePeriod($strDate, $period)) {
            $oPeriod = new Range($period, $strDate);
            $allPeriods = $oPeriod->getSubperiods();
        } else {
            $timezone = count($websiteIds) == 1 ? Site::getTimezoneFor($websiteIds[0]) : false;
            $oPeriod = Period::makePeriodFromQueryParams($timezone, $period, $strDate);
            $allPeriods = array($oPeriod);
        }
        $segment = new Segment($segment, $websiteIds);
        $idSiteIsAll = $idSites == self::REQUEST_ALL_WEBSITES_FLAG;
        $isMultipleDate = Period::isMultiplePeriod($strDate, $period);
        return Archive::factory($segment, $allPeriods, $websiteIds, $idSiteIsAll, $isMultipleDate, $skipAggregationOfSubTables);
    }

    /**
     * Returns a new Archive instance that will query archive data for the given set of
     * sites and periods, using an optional segment.
     * 
     * This method uses an array of Period instances and a Segment instance, instead of strings
     * like {@link build()}.
     * 
     * If you want to create an Archive instance using data found in query parameters,
     * use {@link build()}.
     * 
     * @param Segment $segment The segment to use. For no segment, use `new Segment('', $idSites)`.
     * @param array $periods An array of Period instances.
     * @param array $idSites An array of site IDs (eg, `array(1, 2, 3)`).
     * @param bool $idSiteIsAll Whether `'all'` sites are being queried or not. If true, then
     *                          the result of querying functions will be indexed by site, regardless
     *                          of whether `count($idSites) == 1`.
     * @param bool $isMultipleDate Whether multiple dates are being queried or not. If true, then
     *                             the result of querying functions will be indexed by period,
     *                             regardless of whether `count($periods) == 1`.
     * @param bool $skipAggregationOfSubTables Whether the archive should skip aggregation of all sub-tables
     *
     * @return Archive
     */
    public static function factory(Segment $segment, array $periods, array $idSites, $idSiteIsAll = false, $isMultipleDate = false, $skipAggregationOfSubTables = false)
    {
        $forceIndexedBySite = false;
        $forceIndexedByDate = false;
        if ($idSiteIsAll || count($idSites) > 1) {
            $forceIndexedBySite = true;
        }
        if (count($periods) > 1 || $isMultipleDate) {
            $forceIndexedByDate = true;
        }

        $params = new Parameters($idSites, $periods, $segment, $skipAggregationOfSubTables);

        return new Archive($params, $forceIndexedBySite, $forceIndexedByDate);
    }

    /**
     * Queries and returns metric data in an array.
     * 
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be indexed by site ID.
     * 
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be indexed by period.
     * 
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be indexed by site ID first, then period.
     * 
     * @param string|array $names One or more archive names, eg, `'nb_visits'`, `'Referrers_distinctKeywords'`,
     *                            etc.
     * @return false|numeric|array `false` if there is no data to return, a single numeric value if we're not querying
     *                             for multiple sites/periods, or an array if multiple sites, periods or names are
     *                             queried for.
     */
    public function getNumeric($names)
    {
        $data = $this->get($names, 'numeric');

        $resultIndices = $this->getResultIndices();
        $result = $data->getIndexedArray($resultIndices);

        // if only one metric is returned, just return it as a numeric value
        if (empty($resultIndices)
            && count($result) <= 1
            && (!is_array($names) || count($names) == 1)
        ) {
            $result = (float)reset($result); // convert to float in case $result is empty
        }

        return $result;
    }

    /**
     * Queries and returns blob data in an array.
     * 
     * Reports are stored in blobs as serialized arrays of {@link DataTable\Row} instances, but this
     * data can technically be anything. In other words, you can store whatever you want
     * as archive data blobs.
     *
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be indexed by site ID.
     * 
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be indexed by period.
     * 
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be indexed by site ID first, then period.
     *
     * @param string|array $names One or more archive names, eg, `'Referrers_keywordBySearchEngine'`.
     * @param null|string $idSubtable If we're returning serialized DataTable data, then this refers
     *                                to the subtable ID to return. If set to 'all', all subtables
     *                                of each requested report are returned.
     * @return array An array of appropriately indexed blob data.
     */
    public function getBlob($names, $idSubtable = null)
    {
        $data = $this->get($names, 'blob', $idSubtable);
        return $data->getIndexedArray($this->getResultIndices());
    }

    /**
     * Queries and returns metric data in a DataTable instance.
     * 
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be a DataTable\Map that is indexed by site ID.
     * 
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be a {@link DataTable\Map} that is indexed by period.
     * 
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be a {@link DataTable\Map} indexed by site ID which contains {@link DataTable\Map} instances that are
     * indexed by period.
     * 
     * _Note: Every DataTable instance returned will have at most one row in it. The contents of each
     *        row will be the requested metrics for the appropriate site and period._
     * 
     * @param string|array $names One or more archive names, eg, 'nb_visits', 'Referrers_distinctKeywords',
     *                            etc.
     * @return DataTable|DataTable\Map A DataTable if multiple sites and periods were not requested.
     *                                 An appropriately indexed DataTable\Map if otherwise.
     */
    public function getDataTableFromNumeric($names)
    {
        $data = $this->get($names, 'numeric');
        return $data->getDataTable($this->getResultIndices());
    }

    /**
     * Queries and returns one or more reports as DataTable instances.
     * 
     * This method will query blob data that is a serialized array of of {@link DataTable\Row}'s and
     * unserialize it.
     * 
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be a {@link DataTable\Map} that is indexed by site ID.
     * 
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be a DataTable\Map that is indexed by period.
     * 
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be a {@link DataTable\Map} indexed by site ID which contains {@link DataTable\Map} instances that are
     * indexed by period.
     * 
     * @param string $name The name of the record to get. This method can only query one record at a time.
     * @param int|string|null $idSubtable The ID of the subtable to get (if any).
     * @return DataTable|DataTable\Map A DataTable if multiple sites and periods were not requested.
     *                                 An appropriately indexed {@link DataTable\Map} if otherwise.
     */
    public function getDataTable($name, $idSubtable = null)
    {
        $data = $this->get($name, 'blob', $idSubtable);
        return $data->getDataTable($this->getResultIndices());
    }

    /**
     * Queries and returns one report with all of its subtables loaded.
     * 
     * If multiple sites were requested in {@link build()} or {@link factory()} the result will
     * be a DataTable\Map that is indexed by site ID.
     * 
     * If multiple periods were requested in {@link build()} or {@link factory()} the result will
     * be a DataTable\Map that is indexed by period.
     * 
     * The site ID index is always first, so if multiple sites & periods were requested, the result
     * will be a {@link DataTable\Map indexed} by site ID which contains {@link DataTable\Map} instances that are
     * indexed by period.
     *
     * @param string $name The name of the record to get.
     * @param int|string|null $idSubtable The ID of the subtable to get (if any). The subtable will be expanded.
     * @param int|null $depth The maximum number of subtable levels to load. If null, all levels are loaded.
     *                        For example, if `1` is supplied, then the DataTable returned will have its subtables
     *                        loaded. Those subtables, however, will NOT have their subtables loaded.
     * @param bool $addMetadataSubtableId Whether to add the database subtable ID as metadata to each datatable,
     *                                    or not.
     * @return DataTable|DataTable\Map
     */
    public function getDataTableExpanded($name, $idSubtable = null, $depth = null, $addMetadataSubtableId = true)
    {
        $data = $this->get($name, 'blob', self::ID_SUBTABLE_LOAD_ALL_SUBTABLES);
        return $data->getExpandedDataTable($this->getResultIndices(), $idSubtable, $depth, $addMetadataSubtableId);
    }

    /**
     * Returns the list of plugins that archive the given reports.
     * 
     * @param array $archiveNames
     * @return array
     */
    private function getRequestedPlugins($archiveNames)
    {
        $result = array();
        foreach ($archiveNames as $name) {
            $result[] = self::getPluginForReport($name);
        }
        return array_unique($result);
    }

    /**
     * Returns an object describing the set of sites, the set of periods and the segment
     * this Archive will query data for.
     *
     * @return Parameters
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Helper function that creates an Archive instance and queries for report data using
     * query parameter data. API methods can use this method to reduce code redundancy.
     * 
     * @param string $name The name of the report to return.
     * @param int|string|array $idSite @see {@link build()}
     * @param string $period @see {@link build()}
     * @param string $date @see {@link build()}
     * @param string $segment @see {@link build()}
     * @param bool $expanded If true, loads all subtables. See {@link getDataTableExpanded()}
     * @param int|null $idSubtable See {@link getDataTableExpanded()}
     * @param bool $skipAggregationOfSubTables Whether or not we should skip the aggregation of all sub-tables and only aggregate parent DataTable.
     * @param int|null $depth See {@link getDataTableExpanded()}
     * @return DataTable|DataTable\Map See {@link getDataTable()} and
     *                                 {@link getDataTableExpanded()} for more
     *                                 information
     */
    public static function getDataTableFromArchive($name, $idSite, $period, $date, $segment, $expanded,
                                                   $idSubtable = null, $skipAggregationOfSubTables = false, $depth = null)
    {
        Piwik::checkUserHasViewAccess($idSite);

        if($skipAggregationOfSubTables && ($expanded || $idSubtable)) {
            throw new \Exception("Not expected to skipAggregationOfSubTables when expanded=1 or idSubtable is set.");
        }
        $archive = Archive::build($idSite, $period, $date, $segment, $_restrictSitesToLogin = false, $skipAggregationOfSubTables);
        if ($idSubtable === false) {
            $idSubtable = null;
        }

        if ($expanded) {
            $dataTable = $archive->getDataTableExpanded($name, $idSubtable, $depth);
        } else {
            $dataTable = $archive->getDataTable($name, $idSubtable);
        }

        $dataTable->queueFilter('ReplaceSummaryRowLabel');

        return $dataTable;
    }

    private function appendIdSubtable($recordName, $id)
    {
        return $recordName . "_" . $id;
    }

    /**
     * Queries archive tables for data and returns the result.
     * @param array|string $archiveNames
     * @param $archiveDataType
     * @param null|int $idSubtable
     * @return Archive\DataCollection
     */
    private function get($archiveNames, $archiveDataType, $idSubtable = null)
    {
        if (!is_array($archiveNames)) {
            $archiveNames = array($archiveNames);
        }

        // apply idSubtable
        if ($idSubtable !== null
            && $idSubtable != self::ID_SUBTABLE_LOAD_ALL_SUBTABLES
        ) {
            foreach ($archiveNames as &$name) {
                $name = $this->appendIdsubtable($name, $idSubtable);
            }
        }

        $result = new Archive\DataCollection(
            $archiveNames, $archiveDataType, $this->params->getIdSites(), $this->params->getPeriods(), $defaultRow = null);

        $archiveIds = $this->getArchiveIds($archiveNames);
        if (empty($archiveIds)) {
            return $result;
        }

        $loadAllSubtables = $idSubtable == self::ID_SUBTABLE_LOAD_ALL_SUBTABLES;
        $archiveData = ArchiveSelector::getArchiveData($archiveIds, $archiveNames, $archiveDataType, $loadAllSubtables);
        foreach ($archiveData as $row) {
            // values are grouped by idsite (site ID), date1-date2 (date range), then name (field name)
            $idSite = $row['idsite'];
            $periodStr = $row['date1'] . "," . $row['date2'];

            if ($archiveDataType == 'numeric') {
                $value = $this->formatNumericValue($row['value']);
            } else {
                $value = $this->uncompress($row['value']);
                $result->addMetadata($idSite, $periodStr, 'ts_archived', $row['ts_archived']);
            }

            $resultRow = & $result->get($idSite, $periodStr);
            $resultRow[$row['name']] = $value;
        }

        return $result;
    }

    /**
     * Returns archive IDs for the sites, periods and archive names that are being
     * queried. This function will use the idarchive cache if it has the right data,
     * query archive tables for IDs w/o launching archiving, or launch archiving and
     * get the idarchive from ArchiveProcessor instances.
     */
    private function getArchiveIds($archiveNames)
    {
        $plugins = $this->getRequestedPlugins($archiveNames);

        // figure out which archives haven't been processed (if an archive has been processed,
        // then we have the archive IDs in $this->idarchives)
        $doneFlags = array();
        $archiveGroups = array();
        foreach ($plugins as $plugin) {
            $doneFlag = $this->getDoneStringForPlugin($plugin);

            $doneFlags[$doneFlag] = true;
            if (!isset($this->idarchives[$doneFlag])) {
                $archiveGroup = $this->getArchiveGroupOfPlugin($plugin);

                if($archiveGroup == self::ARCHIVE_ALL_PLUGINS_FLAG) {
                    $archiveGroup = reset($plugins);
                }
                $archiveGroups[] = $archiveGroup;
            }
        }

        $archiveGroups = array_unique($archiveGroups);

        // cache id archives for plugins we haven't processed yet
        if (!empty($archiveGroups)) {
            if (!Rules::isArchivingDisabledFor($this->params->getIdSites(), $this->params->getSegment(), $this->getPeriodLabel())) {
                $this->cacheArchiveIdsAfterLaunching($archiveGroups, $plugins);
            } else {
                $this->cacheArchiveIdsWithoutLaunching($plugins);
            }
        }

        // order idarchives by the table month they belong to
        $idArchivesByMonth = array();
        foreach (array_keys($doneFlags) as $doneFlag) {
            if (empty($this->idarchives[$doneFlag])) {
                continue;
            }

            foreach ($this->idarchives[$doneFlag] as $dateRange => $idarchives) {
                foreach ($idarchives as $id) {
                    $idArchivesByMonth[$dateRange][] = $id;
                }
            }
        }

        return $idArchivesByMonth;
    }

    /**
     * Gets the IDs of the archives we're querying for and stores them in $this->archives.
     * This function will launch the archiving process for each period/site/plugin if
     * metrics/reports have not been calculated/archived already.
     *
     * @param array $archiveGroups @see getArchiveGroupOfReport
     * @param array $plugins List of plugin names to archive.
     */
    private function cacheArchiveIdsAfterLaunching($archiveGroups, $plugins)
    {
        $today = Date::today();

        foreach ($this->params->getPeriods() as $period) {
            $twoDaysBeforePeriod = $period->getDateStart()->subDay(2);
            $twoDaysAfterPeriod = $period->getDateEnd()->addDay(2);

            foreach ($this->params->getIdSites() as $idSite) {
                $site = new Site($idSite);

                // if the END of the period is BEFORE the website creation date
                // we already know there are no stats for this period
                // we add one day to make sure we don't miss the day of the website creation
                if ($twoDaysAfterPeriod->isEarlier($site->getCreationDate())) {
                    Log::verbose("Archive site %s, %s (%s) skipped, archive is before the website was created.",
                        $idSite, $period->getLabel(), $period->getPrettyString());
                    continue;
                }

                // if the starting date is in the future we know there is no visiidsite = ?t
                if ($twoDaysBeforePeriod->isLater($today)) {
                    Log::verbose("Archive site %s, %s (%s) skipped, archive is after today.",
                        $idSite, $period->getLabel(), $period->getPrettyString());
                    continue;
                }

                $this->prepareArchive($archiveGroups, $site, $period);
            }
        }
    }

    /**
     * Gets the IDs of the archives we're querying for and stores them in $this->archives.
     * This function will not launch the archiving process (and is thus much, much faster
     * than cacheArchiveIdsAfterLaunching).
     *
     * @param array $plugins List of plugin names from which data is being requested.
     */
    private function cacheArchiveIdsWithoutLaunching($plugins)
    {
        $idarchivesByReport = ArchiveSelector::getArchiveIds(
            $this->params->getIdSites(), $this->params->getPeriods(), $this->params->getSegment(), $plugins, $this->params->isSkipAggregationOfSubTables());

        // initialize archive ID cache for each report
        foreach ($plugins as $plugin) {
            $doneFlag = $this->getDoneStringForPlugin($plugin);
            $this->initializeArchiveIdCache($doneFlag);
        }

        foreach ($idarchivesByReport as $doneFlag => $idarchivesByDate) {
            foreach ($idarchivesByDate as $dateRange => $idarchives) {
                foreach ($idarchives as $idarchive) {
                    $this->idarchives[$doneFlag][$dateRange][] = $idarchive;
                }
            }
        }
    }

    /**
     * Returns the done string flag for a plugin using this instance's segment & periods.
     * @param string $plugin
     * @return string
     */
    private function getDoneStringForPlugin($plugin)
    {
        return Rules::getDoneStringFlagFor(
                    $this->params->getIdSites(),
                    $this->params->getSegment(),
                    $this->getPeriodLabel(),
                    $plugin,
                    $this->params->isSkipAggregationOfSubTables()
        );
    }

    private function getPeriodLabel()
    {
        $periods = $this->params->getPeriods();
        return reset($periods)->getLabel();
    }

    /**
     * Returns an array describing what metadata to use when indexing a query result.
     * For use with DataCollection.
     *
     * @return array
     */
    private function getResultIndices()
    {
        $indices = array();

        if (count($this->params->getIdSites()) > 1
            || $this->forceIndexedBySite
        ) {
            $indices['site'] = 'idSite';
        }

        if (count($this->params->getPeriods()) > 1
            || $this->forceIndexedByDate
        ) {
            $indices['period'] = 'date';
        }

        return $indices;
    }

    private function formatNumericValue($value)
    {
        // If there is no dot, we return as is
        // Note: this could be an integer bigger than 32 bits
        if (strpos($value, '.') === false) {
            if ($value === false) {
                return 0;
            }
            return (float)$value;
        }

        // Round up the value with 2 decimals
        // we cast the result as float because returns false when no visitors
        return round((float)$value, 2);
    }

    private function uncompress($data)
    {
        return @gzuncompress($data);
    }

    /**
     * Initializes the archive ID cache ($this->idarchives) for a particular 'done' flag.
     *
     * It is necessary that each archive ID caching function call this method for each
     * unique 'done' flag it encounters, since the getArchiveIds function determines
     * whether archiving should be launched based on whether $this->idarchives has a
     * an entry for a specific 'done' flag.
     *
     * If this  function is not called, then periods with no visits will not add
     * entries to the cache. If the archive is used again, SQL will be executed to
     * try and find the archive IDs even though we know there are none.
     */
    private function initializeArchiveIdCache($doneFlag)
    {
        if (!isset($this->idarchives[$doneFlag])) {
            $this->idarchives[$doneFlag] = array();
        }
    }

    /**
     * Returns the archiving group identifier given a plugin.
     *
     * More than one plugin can be called at once when archiving. In such a case
     * we don't want to launch archiving three times for three plugins if doing
     * it once is enough, so getArchiveIds makes sure to get the archive group of
     * all reports.
     *
     * If the period isn't a range, then all plugins' archiving code is executed.
     * If the period is a range, then archiving code is executed individually for
     * each plugin.
     */
    private function getArchiveGroupOfPlugin($plugin)
    {
        if ($this->getPeriodLabel() != 'range') {
            return self::ARCHIVE_ALL_PLUGINS_FLAG;
        }

        return $plugin;
    }

    /**
     * Returns the name of the plugin that archives a given report.
     *
     * @param string $report Archive data name, eg, `'nb_visits'`, `'UserSettings_...'`, etc.
     * @return string Plugin name.
     * @throws \Exception If a plugin cannot be found or if the plugin for the report isn't
     *                    activated.
     */
    private static function getPluginForReport($report)
    {
        // Core metrics are always processed in Core, for the requested date/period/segment
        if (in_array($report, Metrics::getVisitsMetricNames())) {
            $report = 'VisitsSummary_CoreMetrics';
        } // Goal_* metrics are processed by the Goals plugin (HACK)
        else if (strpos($report, 'Goal_') === 0) {
            $report = 'Goals_Metrics';
        } else if (strrpos($report, '_returning') === strlen($report) - strlen('_returning')) { // HACK
            $report = 'VisitFrequency_Metrics';
        }

        $plugin = substr($report, 0, strpos($report, '_'));
        if (empty($plugin)
            || !\Piwik\Plugin\Manager::getInstance()->isPluginActivated($plugin)
        ) {
            throw new \Exception("Error: The report '$report' was requested but it is not available at this stage."
                               . " (Plugin '$plugin' is not activated.)");
        }
        return $plugin;
    }

    /**
     * @param $archiveGroups
     * @param $site
     * @param $period
     */
    private function prepareArchive(array $archiveGroups, Site $site, Period $period)
    {
        $parameters = new ArchiveProcessor\Parameters($site, $period, $this->params->getSegment(), $this->params->isSkipAggregationOfSubTables());
        $archiveLoader = new ArchiveProcessor\Loader($parameters);

        $periodString = $period->getRangeString();

        // process for each plugin as well
        foreach ($archiveGroups as $plugin) {
            $doneFlag = $this->getDoneStringForPlugin($plugin);
            $this->initializeArchiveIdCache($doneFlag);

            $idArchive = $archiveLoader->prepareArchive($plugin);

            if($idArchive) {
                $this->idarchives[$doneFlag][$periodString][] = $idArchive;
            }
        }
    }
}
