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
    const PLUGIN_SQL2WIKI_EDIT_SUMMARY = 'plugin: sql2wiki';

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PLUGIN_SQLITE_QUERY_EXECUTE', 'AFTER', $this, 'handle_plugin_sqlite_query_execute');
        $controller->register_hook('PLUGIN_SQLITE_QUERY_SAVE', 'AFTER', $this, 'handle_plugin_sqlite_query_change');
        $controller->register_hook('PLUGIN_SQLITE_QUERY_DELETE', 'AFTER', $this, 'handle_plugin_sqlite_query_change');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_action_act_preprocess');
    }

    public function handle_plugin_sqlite_query_execute(Doku_Event $event, $param)
    {
        if ($event->data['stmt']->rowCount() == 0) return; // ignore select queries
        $db = $event->data['sqlitedb']->getDbName();
        $indexer = idx_get_indexer();
        $pages = $indexer->lookupKey('sql2wiki_db', $db);
        foreach ($pages as $page) {
            $sql2wiki_data = p_get_metadata($page, 'plugin_sql2wiki');
            if (!$sql2wiki_data) continue;

            foreach ($sql2wiki_data as $sql2wiki_query) {
                // we can have several <sql2wiki> tags on the page. Ignore the tag if it refers to the database
                // other than the one that has triggered the event
                if ($sql2wiki_query['db'] != $db) continue;
                $this->update_query_results($page, $sql2wiki_query);
            }
        }
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
            foreach ($sql2wiki_data as $sql2wiki_query) {
                // we can have several <sql2wiki> tags on the page. Ignore the tag if it refers to the other query
                // than currently saved
                if ($sql2wiki_query['db'] != $upstream || $sql2wiki_query['query_name'] != $query_name) continue;
                $this->update_query_results($page, $sql2wiki_query);
            }
        }
    }

    public function handle_action_act_preprocess(Doku_Event $event, $param)
    {
        global $ID;

        if ($event->data != 'regeneratesqlsitetools') return;
        $sql2wiki_data = p_get_metadata($ID, 'plugin_sql2wiki');
        if (!$sql2wiki_data) return;

        foreach ($sql2wiki_data as $sql2wiki_query) {
            $this->update_query_results($ID, $sql2wiki_query);
        }
        $event->data = 'redirect';
    }

    protected function update_query_results($page, $sql2wiki_query) {
        // get the page content for every query because it could be changed by previous contents updates
        $page_content = file_get_contents(wikiFN($page));

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
        $updated_content = substr_replace($page_content, $query_result_csv, $start - 1, $length);
        // due to the dokuwiki mechanism the save will be only performed if content changed, so we don't need
        // to check it here
        saveWikiText($page, $updated_content, self::PLUGIN_SQL2WIKI_EDIT_SUMMARY);
    }
}