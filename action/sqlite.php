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

    protected $queue = [];

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PLUGIN_SQLITE_QUERY_EXECUTE', 'AFTER', $this, 'handle_plugin_sqlite_query_execute');
        $controller->register_hook('PLUGIN_SQLITE_QUERY_SAVE', 'AFTER', $this, 'handle_plugin_sqlite_query_change');
        $controller->register_hook('PLUGIN_SQLITE_QUERY_DELETE', 'AFTER', $this, 'handle_plugin_sqlite_query_change');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_action_act_preprocess');
        $controller->register_hook('DOKUWIKI_DONE', 'AFTER', $this, 'handle_dokuwiki_done');
        // support for struct inline edits
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'AFTER', $this, 'handle_ajax_call_unknown');
    }

    public function handle_dokuwiki_done(Doku_Event $event, $param)
    {
        global $ID;

        $indexer = idx_get_indexer();
        $dbs = array_keys($this->queue);
        $dbs_pages = $indexer->lookupKey('sql2wiki_db', $dbs);
        $pages = array_unique(array_merge(...array_values($dbs_pages)));
        $current_page_changed = false;
        foreach ($pages as $page) {
            $sql2wiki_data = p_get_metadata($page, 'plugin_sql2wiki');
            if (!$sql2wiki_data) continue;
            $sql2wiki_filtered = array_filter($sql2wiki_data, function ($query) use ($dbs) {
                return in_array($query['db'], $dbs);
            }); // ignore the queries that not refers to currently changed databases
            if ($page == $ID) {
                // we are changing the page we are currently viewing
                $page_content = file_get_contents(wikiFN($page));
                $updated_content = $this->get_updated_page_content($page_content, $page, $sql2wiki_filtered);
                if ($page_content != $updated_content) {
                    // the page can be just updated so wait a second to update changelog correctly
                    sleep(1);
                    saveWikiText($page, $page_content, self::PLUGIN_SQL2WIKI_EDIT_SUMMARY);
                    $current_page_changed = true;
                }
            } else {
                $this->update_query_results($page, $sql2wiki_filtered);
            }
        }
        // refresh the page if it was updated by the plugin
        if ($current_page_changed) {
            $go = wl($ID, true, '&');
            send_redirect($go);
        }
    }

    public function handle_ajax_call_unknown(Doku_Event $event, $param)
    {
        $indexer = idx_get_indexer();
        $dbs = array_keys($this->queue);
        $dbs_pages = $indexer->lookupKey('sql2wiki_db', $dbs);
        $pages = array_unique(array_merge(...array_values($dbs_pages)));
        foreach ($pages as $page) {
            $sql2wiki_data = p_get_metadata($page, 'plugin_sql2wiki');
            if (!$sql2wiki_data) continue;
            $sql2wiki_filtered = array_filter($sql2wiki_data, function ($query) use ($dbs) {
                return in_array($query['db'], $dbs);
            }); // ignore the queries that not refers to currently changed databases
            $this->update_query_results($page, $sql2wiki_filtered);
        }
    }

    public function handle_plugin_sqlite_query_execute(Doku_Event $event, $param)
    {
        if ($event->data['stmt']->rowCount() == 0) return; // ignore select queries
        $db = $event->data['sqlitedb']->getDbName();
        $this->queue[$db] = true;
    }

    public function handle_plugin_sqlite_query_change(Doku_Event $event, $param)
    {
        $upstream = $event->data['upstream'];
        $query_name = $event->data['name'];
        $index_key = $upstream . '.' . $query_name;
        $indexer = idx_get_indexer();
        $pages = $indexer->lookupKey('sql2wiki_query_name', $index_key);
        foreach ($pages as $page) {
            $sql2wiki_data = p_get_metadata($page, 'plugin_sql2wiki');
            if (!$sql2wiki_data) continue;
            $sql2wiki_filtered = array_filter($sql2wiki_data, function ($query) use ($upstream, $query_name) {
                return $query['db'] == $upstream && $query['query_name'] == $query_name;
            }); // ignore the queries that not refers to currently saved query
            $this->update_query_results($page, $sql2wiki_filtered);
        }
    }

    public function handle_action_act_preprocess(Doku_Event $event, $param)
    {
        global $ID, $INFO;

        if ($event->data != 'refreshqueriesresutls') return;
        if ($INFO['perm'] < AUTH_EDIT) return; // only user who can read the page can update queries this way
        if (!isset($INFO['meta']['plugin_sql2wiki'])) return;

        $this->update_query_results($ID, $INFO['meta']['plugin_sql2wiki']);
        $event->data = 'redirect';
    }

    protected function get_updated_page_content($page_content, $page, $sql2wiki_data) {
        $offset = 0;
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
            } else { //unknown query - clear the results
                $query_result_csv = "";
            }

            $start = $sql2wiki_query['start'];
            $end = $sql2wiki_query['end'];
            $length = $end - $start;
            $updated_content = substr_replace($page_content, $query_result_csv, $start + $offset, $length);
            $offset = strlen($updated_content) - strlen($page_content);
            $page_content = $updated_content;
        }
        return $page_content;
    }

    /**
     * @param $page
     * @param $sql2wiki_data
     * @return bool is page content changed
     * @throws Exception
     */
    protected function update_query_results($page, $sql2wiki_data) {
        $page_content = file_get_contents(wikiFN($page));
        $updated_content = $this->get_updated_page_content($page_content, $page, $sql2wiki_data);
        if ($page_content != $updated_content) {
            saveWikiText($page, $page_content, self::PLUGIN_SQL2WIKI_EDIT_SUMMARY);
            return true;
        }
        return false;
    }
}