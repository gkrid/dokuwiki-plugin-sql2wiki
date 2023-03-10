<?php

/**
 * DokuWiki Plugin sql2wiki (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */


class action_plugin_sql2wiki_save extends DokuWiki_Action_Plugin
{
    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        // ensure a page revision is created when sql2wiki data changes:
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'handle_common_wikipage_save');
    }

    /**
     * Forces the page update if sql results have changed
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  optional parameter passed when event was registered
     * @return boolean
     */
    public function handle_common_wikipage_save(Doku_Event $event, $param)
    {
        if ($event->data['contentChanged']) return false; // will be saved for page changes already
        global $ACT;
        global $REV;
        if ($ACT != 'revert' || !$REV) return false;

        $event->data['contentChanged'] = true; // allow empty save

        return true;
    }
}
