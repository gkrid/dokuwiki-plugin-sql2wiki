<?php
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
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'handle_indexer_tasks_run');
//        $controller->register_hook('INDEXER_VERSION_GET', 'BEFORE', $this, 'handle_indexer_version_get');
//        $controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'handle_indexer_page_add');
   
    }

    /**
     * Update queries results for the current page.
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  optional parameter passed when event was registered
     * @return boolean
     */
    public function handle_indexer_tasks_run(Doku_Event $event, $param)
    {
        global $ID;
        try {
            if ((string) $ID === '') return false;

            // do the work
            /** @var $helper \helper_plugin_sql2wiki */
            $helper = $this->loadHelper('sql2wiki');

            /** @var \helper_plugin_sql2wiki_db $db_helper */
            $db_helper = $this->loadHelper('sql2wiki_db');
            $sqlite = $db_helper->getDB();

            $sql2wiki = p_get_metadata($ID, 'plugin_sql2wiki');
            if(!$sql2wiki) return false;
            $update_page = false;
            foreach ($sql2wiki as $query_meta) {
                $db = $query_meta['db'];
                list($query, $params) = $helper->find_and_prepare_query($db, $query_meta['query_name'],
                    $query_meta['args'], $ID);
                $res = $sqlite->query("SELECT last_checked, last_changed FROM cache WHERE db=? AND query=? AND params=?",
                    $db, $query, serialize($params));

                $last_checked = null;
                $last_changed = null;
                $row = $sqlite->res2row($res);
                if ($row !== false) {
                    $last_checked = (int) $row['last_checked'];
                    $last_changed = (int) $row['last_changed'];
                }

                $db_mtime = $helper->db_mtime($db);
                if ($last_checked === null) { // the query is not in the cache - store it
                    $last_checked = $db_mtime;
                    $last_changed = $db_mtime;
                    $arr = $helper->run_query($db, $query, $params);
                    $sqlite->storeEntry('cache', [
                        'db' => $db,
                        'query' => $query,
                        'params' => serialize($params),
                        'result' => serialize($arr),
                        'last_checked' => $last_checked,
                        'last_changed' => $last_changed
                    ]);
                } elseif ((int) $last_checked !== $db_mtime) { // db mtime is newer than cache - check for updates
                    // update last_checked date
                    $last_checked = $db_mtime;
                    $sqlite->query("UPDATE cache SET last_checked=? WHERE db=? AND query=? AND params=?",
                        $last_checked, $db, $query, $params);

                    $res = $sqlite->query("SELECT arr FROM cache WHERE db=? AND query=? AND params=?",
                        $db, $query, serialize($params));
                    $old_arr = $sqlite->res2single($res);
                    $new_arr = serialize($helper->run_query($db, $query, $params));
                    if ($old_arr !== $new_arr) { // something has changed
                        $last_changed = $db_mtime;
                        $sqlite->query("UPDATE cache SET result=?, last_changed=? WHERE db=? AND query=? AND params=?",
                            $new_arr, $last_changed, $db, $query, $params);

                    }
                }

                // check if we need to update the page
                $page_last_change = @filemtime(wikiFN($ID));
                if ($page_last_change < $last_changed) {
                    $update_page = true;
                }
            }
            if ($update_page) {
                // update the page
                saveWikiText($ID, file_get_contents(wikiFN($ID)), 'plugin: sql2wiki');
                return true;
            }
        } catch (\RuntimeException $e) {
            print $e->getMessage() . NL; // print message for logging purposes
            return false;
        }
        return false;
    }
//    public function handle_indexer_version_get(Doku_Event $event, $param)
//    {
//        $event->data['plugin_sql2wiki'] = '0.1';
//    }
//    public function handle_indexer_page_add(Doku_Event $event, $param)
//    {
//        $sql2wiki_data = p_get_metadata($event->data['page'], 'plugin_sql2wiki');
//        if (!$sql2wiki_data) return;
//
//        $queries = array_map(function ($v) {
//            return $v['db'] . '.' . $v['query_name'];
//        }, $sql2wiki_data);
//
//        $event->data['metadata']['sql2wiki'] = $queries;
//    }

}

