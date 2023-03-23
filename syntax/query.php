<?php

use dokuwiki\plugin\sql2wiki\Csv;

/**
 * DokuWiki Plugin sql2wiki (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */
class syntax_plugin_sql2wiki_query extends \dokuwiki\Extension\SyntaxPlugin
{
    /** @inheritDoc */
    public function getType()
    {
        return 'protected';
    }

    /** @inheritDoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 0;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<sql2wiki.*?>.*?</sql2wiki>',$mode,'plugin_sql2wiki_query');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($match);
        if ($xml === false) {
            msg('Syntax: "'.hsc($match) . '" is not valid xml', -1);
            return null;
        }
        $attributes = [];
        foreach($xml[0]->attributes() as $a => $b) {
            $attributes[$a] = (string) $b;
        }
        if (!isset($attributes['db']) || !isset($attributes['query'])) {
            msg('"db" and "query" attributes are required.', -1);
            return null;
        }
        $tag_value = (string) $xml[0];
//        if ($tag_value !== '') {
//            msg('sql2wiki tag content must be empty.', -1);
//            return null;
//        }

//        list($db, $query_name) = explode('.', $tag_value);

        $parsers = [];
        $needle = 'parser_';
        foreach ($attributes as $name => $value) {
            $length = strlen($needle);
            if (substr($name, 0, $length) === $needle) {
                list($_, $col) = explode('_', $name);
                if (preg_match('/([[:alpha:]]+)\((.*)\)/', $value, $matches)) {
                    $class = $matches[1];
                    $config = json_decode($matches[2], true);
                    $parsers[$col] = ['class' => $class, 'config' => $config];
                } else {
                    $parsers[$col] = ['class' => $value, 'config' => null];
                }
            }
        }

        $args = [];
        if (isset($attributes['args'])) {
            $args = array_map('trim', explode(',', $attributes['args']));
        }

        // updated the position to point to the tag content
        $start = $pos + strpos($match, "\n");
        $end = $pos + strlen($match) - strlen('</sql2wiki>');
        $data = ['db' => $attributes['db'],
            'query_name' => $attributes['query'],
            'parsers' => $parsers,
            'args' => $args,
            'value' => $tag_value,
            'start' => $start,
            'end' => $end];
        return [$state, $data];
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($data === null) return false;
        list($state, $match) = $data;

        if ($mode == 'metadata') {
            if (!isset($renderer->meta['plugin_sql2wiki'])) {
                $renderer->meta['plugin_sql2wiki'] = [];
            }
            $renderer->meta['plugin_sql2wiki'][] = $match;
            return true;
        }
        if ($mode == 'xhtml') {
            $result = Csv::csv2arr($match['value']);

            if (count($result) == 0) {
                $renderer->cdata($this->getLang('none'));
                return true;
            }

            // check if we use any parsers
            $parsers = $match['parsers'];
            if (count($parsers) > 0) {
                $class_name = '\dokuwiki\plugin\struct\meta\Column';
                if (!class_exists($class_name)) {
                    msg('Install struct plugin to use parsers', -1);
                    return false;
                }
                $parser_types = $class_name::allTypes();
            }

            $renderer->doc .= '<table class="inline">';
            $renderer->doc .= '<tr>';
            $headers = array_shift($result);
            foreach ($headers as $header) {
                $renderer->doc .= '<th>' . hsc($header) . '</th>';
            }
            $renderer->doc .= '</tr>';

            foreach ($result as $row) {
                $renderer->doc .= '<tr>';
                $tds = array_values($row);
                foreach ($tds as $i => $td) {
                    if ($td === null) $td = '␀';
                    if (isset($parsers[$i])) {
                        $parser_class = $parsers[$i]['class'];
                        $parser_config = $parsers[$i]['config'];
                        if (!isset($parser_types[$parser_class])) {
                            msg('Unknown parser: ' . $parser_class, -1);
                            $renderer->doc .= '<td>' . hsc($td) . '</td>';
                        } else {
                            /** @var \dokuwiki\plugin\struct\types\AbstractBaseType $parser */
                            $parser = new $parser_types[$parser_class]($parser_config);
                            $renderer->doc .= '<td>';
                            $parser->renderValue($td, $renderer, $mode);
                            $renderer->doc .= '</td>';
                        }
                    } else {
                        $renderer->doc .= '<td>' . hsc($td) . '</td>';
                    }
                }
                $renderer->doc .= '</tr>';
            }

            $renderer->doc .= '</table>';
            return true;
        }

//        if ($mode == 'xhtml' && $state == DOKU_LEXER_ENTER) {
//            /** @var $DBI helper_plugin_sqlite */
//            $DBI = plugin_load('helper', 'sqlite');
//
//            /** @var $helper helper_plugin_sqlite */
//            $sqlite_db = plugin_load('helper', 'sqlite');
//            $sqlite_db->init('sqlite', DOKU_PLUGIN . 'sqlite/db/');
//
//            $db = $match['db'];
//            $query_name = $match['query_name'];
//            $parsers = $match['parsers'];
//            $args = $match['args'];
//
//            // process args special variables
//            $args = str_replace(
//                array(
//                    '$ID$',
//                    '$NS$',
//                    '$PAGE$',
//                    '$USER$',
//                    '$TODAY$'
//                ),
//                array(
//                    $INFO['id'],
//                    getNS($INFO['id']),
//                    noNS($INFO['id']),
//                    isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : '',
//                    date('Y-m-d')
//                ),
//                $args
//            );
//
//            $res = $sqlite_db->query("SELECT sql FROM queries WHERE db=? AND name=?", $db, $query_name);
//            $sql = $sqlite_db->res2single($res);
//            if (empty($sql)) {
//                msg('Unknown database: ' . $db . ' or query name: ' . $query_name, -1);
//                return false;
//            }
//
//            if (!$DBI->init($db, '')) {
//                msg('Cannot initialize db: ' . $db, -1);
//                return false;
//            }
//
//            $res = $DBI->query($sql, $args);
//            if (!$res) {
//                msg('Cannot execute query: ' . $sql, -1);
//                return false;
//            }
//            $result = $DBI->res2arr($res);
//
//            if (!$result) {
//                $renderer->cdata($this->getLang('none'));
//                return true;
//            }
//
//            // check if we use any parsers
//            if (count($parsers) > 0) {
//                $class_name = '\dokuwiki\plugin\struct\meta\Column';
//                if (!class_exists($class_name)) {
//                    msg('Install struct plugin to use parsers', -1);
//                    return false;
//                }
//                $parser_types = $class_name::allTypes();
//            }
//
//            $renderer->doc .= '<div>';
//            $ths = array_keys($result[0]);
//            $renderer->doc .= '<table class="inline">';
//            $renderer->doc .= '<tr>';
//            foreach ($ths as $th) {
//                $renderer->doc .= '<th>' . hsc($th) . '</th>';
//            }
//            $renderer->doc .= '</tr>';
//            foreach ($result as $row) {
//                $renderer->doc .= '<tr>';
//                $tds = array_values($row);
//                foreach ($tds as $i => $td) {
//                    if ($td === null) $td = '␀';
//                    if (isset($parsers[$i])) {
//                        $parser_class = $parsers[$i]['class'];
//                        $parser_config = $parsers[$i]['config'];
//                        if (!isset($parser_types[$parser_class])) {
//                            msg('Unknown parser: ' . $parser_class, -1);
//                            $renderer->doc .= '<td>' . hsc($td) . '</td>';
//                        } else {
//                            /** @var \dokuwiki\plugin\struct\types\AbstractBaseType $parser */
//                            $parser = new $parser_types[$parser_class]($parser_config);
//                            $renderer->doc .= '<td>';
//                            $parser->renderValue($td, $renderer, $mode);
//                            $renderer->doc .= '</td>';
//                        }
//                    } else {
//                        $renderer->doc .= '<td>' . hsc($td) . '</td>';
//                    }
//                }
//                $renderer->doc .= '</tr>';
//            }
//            $renderer->doc .= '</table>';
//            $renderer->doc .= '</div>';
//            return true;
//        }
        return false;
    }
}

