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
        $controller->register_hook('INDEXER_VERSION_GET', 'BEFORE', $this, 'handle_indexer_version_get');
        $controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'handle_indexer_page_add');
   
    }

    public function handle_indexer_version_get(Doku_Event $event, $param)
    {
        $event->data['plugin_sql2wiki'] = '0.1';
    }

    public function handle_indexer_page_add(Doku_Event $event, $param)
    {
        $sql2wiki_data = p_get_metadata($event->data['page'], 'plugin_sql2wiki');
        if (!$sql2wiki_data) return;
        $event->data['metadata']['sql2wiki_db'] = array_column($sql2wiki_data, 'db');
        $event->data['metadata']['sql2wiki_query_name'] = array_map(function($data) {
            return $data['db'] . '.' . $data['query_name'];
        }, $sql2wiki_data);
    }

}

