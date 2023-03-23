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

class action_plugin_sql2wiki_indexer extends \dokuwiki\Extension\ActionPlugin
{

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PLUGIN_SQLITE_QUERY_EXECUTE', 'AFTER', $this, 'handle_plugin_sqlite_query_execute');
        $controller->register_hook('INDEXER_VERSION_GET', 'BEFORE', $this, 'handle_indexer_version_get');
        $controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'handle_indexer_page_add');
   
    }

    public function handle_plugin_sqlite_query_execute(Doku_Event $event, $param) {
        if ($event->data['stmt']->rowCount() == 0) return; // ignore select queries
        $db = $event->data['sqlitedb']->getDbName();
        $indexer = idx_get_indexer();
        $pages = $indexer->lookupKey('sql2wiki', $db);
        foreach ($pages as $page) {
            $sql2wiki_data = p_get_metadata($page, 'plugin_sql2wiki');
            if (!$sql2wiki_data) continue;

            foreach ($sql2wiki_data as $sql2wiki_query) {
                // we can have several <sql2wiki> tags on the page. Ignore the tag if it refers to the database
                // other than the one that has triggered the event
                if ($sql2wiki_query['db'] != $db) continue;

                // get the page content for every query because it could be changed
                $page_content = file_get_contents(wikiFN($page));

                $sqliteDb = new SQLiteDB($sql2wiki_query['db'], '');
                $querySaver = new QuerySaver($sqliteDb->getDBName());
                $query = $querySaver->getQuery($sql2wiki_query['query_name']);
                if ($query == null) continue; // unknown query

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
                $query_result_csv = "\n" . Csv::arr2csv($result); // to wrap the <sql2wiki> tag

                $start = $sql2wiki_query['start'];
                $end = $sql2wiki_query['end'];
                $length = $end - $start;
                $updated_content = substr_replace($page_content, $query_result_csv, $start - 1, $length);
                // due to the dokuwiki mechanism the save will be only performed if content changed, so we don't need
                // to check it there
                saveWikiText($page, $updated_content, 'plugin: sql2wiki');
            }
        }
    }

    public function handle_indexer_version_get(Doku_Event $event, $param)
    {
        $event->data['plugin_sql2wiki'] = '0.1';
    }
    public function handle_indexer_page_add(Doku_Event $event, $param)
    {
        $sql2wiki_data = p_get_metadata($event->data['page'], 'plugin_sql2wiki');
        if (!$sql2wiki_data) return;
        $event->data['metadata']['sql2wiki'] = array_column($sql2wiki_data, 'db');
    }

}

