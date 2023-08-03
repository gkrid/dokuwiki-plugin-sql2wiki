<?php

use dokuwiki\plugin\sqlite\QuerySaver;
use dokuwiki\plugin\sqlite\SQLiteDB;

use dokuwiki\plugin\sql2wiki\Csv;

/**
 * DokuWiki Plugin sql2wiki (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

class action_plugin_sql2wiki_sqlite extends \dokuwiki\Extension\ActionPlugin
{
    const PLUGIN_SQL2WIKI_EDIT_SUMMARY = 'plugin sql2wiki';
    const PLUGIN_SQL2WIKI_EDIT_SUMMARY_INFINITE_LOOP = 'plugin sql2wiki: syntax commented out: query results may depend on page revision';

    /** @var array databases that has changed */
    protected $queue = [];

    protected function queue_put($db, $query_name='') {
        if (!isset($this->queue[$db])) {
            $this->queue[$db] = [];
        }
        // if query_name is not specified, update all database queries and don't consider future query_name
        if (empty($query_name)) {
            $this->queue[$db] = true;
        }
        // add new query_name if we still did not request all database queries update
        if (is_array($this->queue[$db])) {
            $this->queue[$db][$query_name] = true;
        }
    }

    protected function queue_filtered($sql2wiki_data) {
        // ignore the queries that have not been changed in this request
        $queue = $this->queue;
        return array_filter($sql2wiki_data, function ($query) use ($queue) {
            $db = $query['db'];
            $query_name = $query['query_name'];
            return isset($queue[$db]) && ($queue[$db] === true || isset($queue[$db][$query_name]));
        });
    }

    protected function queue_clean() {
        $this->queue = [];
    }

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PLUGIN_SQLITE_QUERY_EXECUTE', 'AFTER', $this, 'handle_plugin_sqlite_query_execute');
        $controller->register_hook('PLUGIN_SQLITE_QUERY_SAVE', 'AFTER', $this, 'handle_plugin_sqlite_query_change');
        $controller->register_hook('PLUGIN_SQLITE_QUERY_DELETE', 'AFTER', $this, 'handle_plugin_sqlite_query_change');
        // update pages after all saving and metadata updating has happened
        $controller->register_hook('DOKUWIKI_DONE', 'AFTER', $this, 'update_pages_content');
        // if we have updated the page we are currently viewing, redirect to updated version
        // this is why we are using ACTION_HEADERS_SEND event here
        $controller->register_hook('ACTION_HEADERS_SEND', 'AFTER', $this, 'check_current_page_for_updates');
        // support for struct inline edits
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'AFTER', $this, 'update_pages_content');
    }

    public function check_current_page_for_updates(Doku_Event $event, $param) {
        global $ID, $ACT;

        if ($ACT != 'show') return; // update the page content only when we viewing it

        $sql2wiki_data = p_get_metadata($ID, 'plugin_sql2wiki');
        if (!$sql2wiki_data) return;

        // check if we have some resutls updates
        $something_changed = $this->update_query_results($ID, $sql2wiki_data, 1);
        if ($something_changed) {
            // update other pages in queue and redirect
            $this->update_pages_content($event, [$ID]);
            $go = wl($ID, '', true, '&');
            send_redirect($go);
        }
    }

    public function update_pages_content(Doku_Event $event, $param) {
        global $ID;

        $filter_ids = []; // don't update specific pages
        if (!is_null($param)) $filter_ids = $param;

        $indexer = idx_get_indexer();
        $dbs = array_keys($this->queue);
        $dbs_pages = $indexer->lookupKey('sql2wiki_db', $dbs);
        $pages = array_unique(array_merge(...array_values($dbs_pages)));
        foreach ($pages as $page) {
            if (in_array($page, $filter_ids)) continue;
            $sql2wiki_data = p_get_metadata($page, 'plugin_sql2wiki');
            if (!$sql2wiki_data) continue;
            $sql2wiki_filtered = $this->queue_filtered($sql2wiki_data);
            // the $ID is usually updated in check_current_page_for_updates
            // but when $ACT != 'show' the current page might be not updated yet
            $sleep = $page == $ID ? 1 : 0;
            $this->update_query_results($page, $sql2wiki_filtered, $sleep);
        }
        $this->queue_clean();
    }

    public function handle_plugin_sqlite_query_execute(Doku_Event $event, $param)
    {
        if ($event->data['stmt']->rowCount() == 0) return; // ignore select queries
        $db = $event->data['sqlitedb']->getDbName();
        $this->queue_put($db);
    }

    public function handle_plugin_sqlite_query_change(Doku_Event $event, $param)
    {
        $upstream = $event->data['upstream'];
        $query_name = $event->data['name'];
        $this->queue_put($upstream, $query_name);
    }

    protected function get_page_content_with_wrapped_tags($page_content, $sql2wiki_data) {
        $offset = 0;
        foreach ($sql2wiki_data as $sql2wiki_query) {
            $pos = $sql2wiki_query['pos'] - 1;
            $match = $sql2wiki_query['match'];
            $wrapped_tag = '<code>' . $match . '</code>';
            $updated_content = substr_replace($page_content, $wrapped_tag, $pos + $offset, strlen($match));
            $offset = strlen($updated_content) - strlen($page_content);
            $page_content = $updated_content;
        }
        return $page_content;
    }

    protected function get_updated_page_content($page_content, $page, $sql2wiki_data) {
        $offset = 0;
        $logger_details = [
            'page' => $page,
            'before_page_content' => $page_content,
            'sql2wiki_data' => $sql2wiki_data,
            'results' => []
        ];

        foreach ($sql2wiki_data as $sql2wiki_query) {
            $sqliteDb = new SQLiteDB($sql2wiki_query['db'], '');
            $querySaver = new QuerySaver($sqliteDb->getDBName());
            $query = $querySaver->getQuery($sql2wiki_query['query_name']);
            if ($query) {
                $params = str_replace([
                    '$ID$',
                    '$NS$',
                    '$PAGE$'
                ], [
                    $page,
                    getNS($page),
                    noNS($page)
                ], $sql2wiki_query['args']);

                $result = $sqliteDb->queryAll($query, $params);
                if (isset($result[0])) { // generate header if any row exists
                    array_unshift($result, array_keys($result[0]));
                }
                $query_result_csv = "\n" . Csv::arr2csv($result); // "\n" to wrap the <sql2wiki> tag
                $logger_details['results'][] = $result;
            } else { //unknown query - clear the results
                $query_result_csv = "";
                $logger_details['results'][] = null;
            }

            $start = $sql2wiki_query['start'];
            $end = $sql2wiki_query['end'];
            $length = $end - $start;
            $updated_content = substr_replace($page_content, $query_result_csv, $start + $offset, $length);
            $offset = strlen($updated_content) - strlen($page_content);
            $page_content = $updated_content;
        }
        $before_sql2wiki_opening_tags = substr_count($logger_details['before_page_content'], '<sql2wiki');
        $before_sql2wiki_closing_tags = substr_count($logger_details['before_page_content'], '</sql2wiki>');
        $after_sql2wiki_opening_tags = substr_count($page_content, '<sql2wiki');
        $after_sql2wiki_closing_tags = substr_count($page_content, '</sql2wiki>');
        if ($before_sql2wiki_opening_tags != $after_sql2wiki_opening_tags ||
            $before_sql2wiki_closing_tags != $after_sql2wiki_closing_tags ||
            $after_sql2wiki_opening_tags != $after_sql2wiki_closing_tags
        ) {
            $logger_details['after_page_content'] = $page_content;
            \dokuwiki\Logger::error('sql2wiki', $logger_details, __FILE__, __LINE__);
        }
        return $page_content;
    }

    protected function update_query_results($page, $sql2wiki_data, $sleep=0) {
        $page_content = file_get_contents(wikiFN($page));
        $updated_content = $this->get_updated_page_content($page_content, $page, $sql2wiki_data);
        if ($page_content != $updated_content) {
            sleep($sleep); // wait if we are processing currently viewed page
            saveWikiText($page, $updated_content, self::PLUGIN_SQL2WIKI_EDIT_SUMMARY);
            $next_update = $this->get_updated_page_content($page_content, $page, $sql2wiki_data);
            // this may mean that the query results depend on page revisions which leads to infinite loop
            if ($updated_content != $next_update) {
                // comment out <sql2wiki> tags to prevent infinite loop
                $wrapped_content = $this->get_page_content_with_wrapped_tags($page_content, $sql2wiki_data);
                sleep(1); // wait for all types of updates since we have just updated the page
                saveWikiText($page, $wrapped_content, self::PLUGIN_SQL2WIKI_EDIT_SUMMARY_INFINITE_LOOP);
            }
            return true;
        }
        return false;
    }
}