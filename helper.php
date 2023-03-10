<?php
/**
 * DokuWiki Plugin sql2wiki (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */
class helper_plugin_sql2wiki extends \dokuwiki\Extension\Plugin
{
    public function find_and_prepare_query($db, $query_name, $args, $page_id) {
        /** @var $helper helper_plugin_sqlite */
        $sqlite_db = plugin_load('helper', 'sqlite');
        $sqlite_db->init('sqlite', '');

        // process args special variables
        $args = str_replace(
            array(
                '$ID$',
                '$NS$',
                '$PAGE$'
            ),
            array(
                $page_id,
                getNS($page_id),
                noNS($page_id)
            ),
            $args
        );

        $res = $sqlite_db->query("SELECT sql FROM queries WHERE db=? AND name=?", $db, $query_name);
        $query = $sqlite_db->res2single($res);
        if (empty($query)) {
            throw \RuntimeException('Unknown database: ' . $db . ' or query name: '.$query_name);
        }

        return [$query, $args];
    }

    public function db_mtime($db) {
        /** @var $target_db helper_plugin_sqlite */
        $target_db = plugin_load('helper', 'sqlite');
        if(!$target_db->init($db, '')) {
            throw \RuntimeException('Cannot initialize target db: '.$db, -1);
        }

        return @filemtime($target_db->getAdapter()->getDbFile());
    }

    public function run_query($db, $query, $params) {
        /** @var $target_db helper_plugin_sqlite */
        $target_db = plugin_load('helper', 'sqlite');
        if(!$target_db->init($db, '')) {
            throw \RuntimeException('Cannot initialize target db: '.$db, -1);
        }

        $res = $target_db->query($query, $params);
        if(!$res) {
            throw \RuntimeException('Cannot execute query: ' . $query, -1);
        }
        return $target_db->res2arr($res);
    }
}
