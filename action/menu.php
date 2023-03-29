<?php

use dokuwiki\plugin\sql2wiki\RefreshQueriesResutls;

/**
 * DokuWiki Plugin sql2wiki (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

class action_plugin_sql2wiki_menu extends DokuWiki_Action_Plugin
{
    /** @inheritdoc */
    function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'handle_menu_items_assembly');
    }

    public function handle_menu_items_assembly(Doku_Event $event)
    {
        global $ACT, $INFO;

        if(!$INFO['writable']) return false; // only users who have write permission can refresh the queries results
        if ($ACT != 'show') return false; // the action is available only in 'show' state
        if ($event->data['view'] != 'page') return false;
        if (!$INFO['meta']['plugin_sql2wiki']) return false; // no queries on the current page

        $label = $this->getLang('btn_refresh_queries_results');
        array_splice($event->data['items'], -1, 0, [new RefreshQueriesResutls($label)]);

        return true;
    }

}