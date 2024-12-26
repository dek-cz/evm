<?php
declare(strict_types = 1);
/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace DekApps\Evm\Diagnostics;

use Closure;
use DekApps\Evm\Evm as EventManager;
use Doctrine\Common\EventArgs;
use Nette\DI\Container as DIContainer;
use Nette\Utils\Arrays;
use Nette\Utils\Callback;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionProperty;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Helpers as TracyHelpers;

class Panel implements \Tracy\IBarPanel
{

    /**
     * @var \Nette\DI\Container
     */
    private $sl;

    /**
     * @var array
     */
    private $events = [];

    /**
     * @var array
     */
    private $dispatchLog = [];

    /**
     * @var array
     */
    private $dispatchTree = [];

    /**
     * @var array|NULL
     */
    private $dispatchTreePointer;

    /**
     * @var array
     */
    private $listenerIds = [];

    /**
     * @var array
     */
    private $inlineCallbacks = [];

    /**
     * @var array|NULL
     */
    private $registeredClasses;

    /**
     * @var bool|array<string, mixed>
     */
    public $renderPanel = TRUE;

    public function __construct(DIContainer $sl)
    {
        $this->sl = $sl;
    }

    public function setEventManager(EventManager $evm)
    {
        $evm->setPanel($this);
    }

    public function setServiceIds(array $listenerIds)
    {
        if (!$this->renderPanel || (is_array($this->renderPanel) && !$this->renderPanel['listeners'])) {
            return;
        }
        $this->listenerIds = $listenerIds;
    }

    public function eventDispatch($eventName, EventArgs $args = NULL)
    {
        if (!$this->renderPanel) {
            return;
        }
        $this->events[] = $eventName;

        if (!is_array($this->renderPanel) || $this->renderPanel['dispatchLog']) {
            $this->dispatchLog[$eventName][] = $args;
        }

        if (!is_array($this->renderPanel) || $this->renderPanel['dispatchTree']) {
            // meta is array of (parent-ref, name, args, children)
            $meta = [&$this->dispatchTreePointer, $eventName, $args, []];
            if ($this->dispatchTreePointer === NULL) {
                $this->dispatchTree[] = &$meta;
            } else {
                $this->dispatchTreePointer[3][] = &$meta;
            }
            $this->dispatchTreePointer = &$meta;
        }
    }

    public function eventDispatched($eventName, EventArgs $args = NULL)
    {
        if (!$this->renderPanel || (is_array($this->renderPanel) && !$this->renderPanel['dispatchTree'])) {
            return;
        }
        $this->dispatchTreePointer = &$this->dispatchTreePointer[0];
    }

    public function inlineCallbacks($eventName, $inlineCallbacks)
    {
        if (!$this->renderPanel) {
            return;
        }
        $this->inlineCallbacks[$eventName] = (array) $inlineCallbacks;
    }

    /**
     * Renders HTML code for custom tab.
     *
     * @return string|NULL
     */
    public function getTab()
    {
        if (empty($this->events)) {
            return NULL;
        }


        return '<span title="Events">'
            . '<span class="tracy-label">' . count(Arrays::flatten($this->dispatchLog)) . ' calls</span>'
            . '</span>';
    }

    /**
     * Renders HTML code for custom panel.
     *
     * @return string|NULL
     */
    public function getPanel()
    {
        if (!$this->renderPanel) {
            return '';
        }

        if (empty($this->events)) {
            return NULL;
        }

        $visited = [];

        $h = 'htmlspecialchars';

        $s = '';
        $s .= $this->renderPanelDispatchLog($visited);
        $s .= $this->renderPanelEvents($visited);
        $s .= $this->renderPanelListeners($visited);

        if ($s) {
            $s .= '<tr class="blank"><td colspan=2>&nbsp;</td></tr>';
        }

        $s .= $this->renderPanelDispatchTree();

        $totalEvents = (string) count($this->events);

        return '<style>' . $this->renderStyles() . '</style>' .
            '<h1>' . $h($totalEvents) . ' registered events</h1>' .
            '<div class="nette-inner tracy-inner nette-KdybyEventsPanel"><table>' . $s . '</table></div>';
    }

    private function renderPanelDispatchLog(&$visited)
    {
        if (!$this->renderPanel || (is_array($this->renderPanel) && !$this->renderPanel['dispatchLog'])) {
            return '';
        }

        $h = 'htmlspecialchars';
        $s = '';

        foreach ($this->dispatchLog as $eventName => $calls) {
            $s .= '<tr><th colspan=2 id="' . $this->formatEventId($eventName) . '">' . count($calls) . 'x ' . $h($eventName) . '</th></tr>';
            $visited[] = $eventName;

            $s .= $this->renderListeners($this->getInlineCallbacks($eventName));

            if (empty($this->listenerIds[$eventName])) {
                $s .= '<tr><td>&nbsp;</td><td>no system listeners</th></tr>';
            } else {
                $s .= $this->renderListeners($this->listenerIds[$eventName]);
            }

            $s .= $this->renderCalls($calls);
        }

        return $s;
    }

    private function renderPanelEvents(&$visited)
    {
        if (!$this->renderPanel || (is_array($this->renderPanel) && !$this->renderPanel['events'])) {
            return '';
        }

        $h = 'htmlspecialchars';
        $s = '';
        foreach ($this->events as $event) {
            if (in_array($event, $visited, TRUE)) {
                continue;
            }

            $calls = $this->getEventCalls($event);
            $s .= '<tr class="blank"><td colspan=2>&nbsp;</td></tr>';
            $s .= '<tr><th colspan=2>' . count($calls) . 'x ' . $h($even) . '</th></tr>';
            $visited[] = $event;

            $s .= $this->renderListeners($this->getInlineCallbacks($event));

            if (empty($this->listenerIds[$event])) {
                $s .= '<tr><td>&nbsp;</td><td>no system listeners</th></tr>';
            } else {
                $s .= $this->renderListeners($this->listenerIds[$event]);
            }

            $s .= $this->renderCalls($calls);
        }

        return $s;
    }

    private function renderPanelListeners(&$visited)
    {
        if (!$this->renderPanel || (is_array($this->renderPanel) && !$this->renderPanel['listeners'])) {
            return '';
        }

        $h = 'htmlspecialchars';
        $s = '';
        foreach ($this->listenerIds as $eventName => $ids) {
            if (in_array($eventName, $visited, TRUE)) {
                continue;
            }

            $calls = $this->getEventCalls($eventName);
            $s .= '<tr class="blank"><td colspan=2>&nbsp;</td></tr>';
            $s .= '<tr><th colspan=2>' . count($calls) . 'x ' . $h($eventName) . '</th></tr>';

            $s .= $this->renderListeners($this->getInlineCallbacks($eventName));

            if (empty($ids)) {
                $s .= '<tr><td>&nbsp;</td><td>no system listeners</th></tr>';
            } else {
                $s .= $this->renderListeners($ids);
            }

            $s .= $this->renderCalls($calls);
        }

        return $s;
    }

    private function renderPanelDispatchTree()
    {
        if (!$this->renderPanel || (is_array($this->renderPanel) && !$this->renderPanel['dispatchTree'])) {
            return '';
        }

        $s = '<tr><th colspan=2>Summary event call graph</th></tr>';
        foreach ($this->dispatchTree as $item) {
            $s .= '<tr><td colspan=2>';
            $s .= $this->renderTreeItem($item);
            $s .= '</td></tr>';
        }

        return $s;
    }

    /**
     * Renders an item in call graph.
     *
     * @param array $item
     * @return string
     */
    private function renderTreeItem(array $item)
    {
        $h = 'htmlspecialchars';

        $s = '<ul><li>';
        $s .= '<a href="#' . $this->formatEventId($item[1]) . '">' . $h($item[1]) . '</a>';
        if ($item[2]) {
            $s .= ' (<a href="#' . $this->formatArgsId($item[2]) . '">' . get_class($item[2]) . '</a>)';
        }

        if ($item[3]) {
            foreach ($item[3] as $child) {
                $s .= $this->renderTreeItem($child);
            }
        }

        return $s . '</li></ul>';
    }

    private function getEventCalls($eventName)
    {
        return !empty($this->dispatchLog[$eventName]) ? $this->dispatchLog[$eventName] : [];
    }

    private function getInlineCallbacks($eventName)
    {
        return !empty($this->inlineCallbacks[$eventName]) ? $this->inlineCallbacks[$eventName] : [];
    }

    private function renderListeners($ids)
    {

        $registeredClasses = $this->getClassMap();

        $h = 'htmlspecialchars';

        $shortFilename = static function (ReflectionFunctionAbstract $refl) {
            $title = '.../' . basename($refl->getFileName() ?: 'unknown.php') . ':' . ((string) $refl->getStartLine());

            /** @var string|NULL $editor */
            $editor = TracyHelpers::editorUri($refl->getFileName() ?: 'unknown.php', $refl->getStartLine() ?: 0);
            if ($editor !== NULL) {
                return sprintf(' defined at <a href="%s">%s</a>', htmlspecialchars($editor), $title);
            }

            return ' defined at ' . $title;
        };

        $s = '';
        foreach ($ids as $id) {
            if (is_callable($id)) {
                $s .= '<tr><td width=18>&nbsp;</td><td><pre class="nette-dump"><span class="nette-dump-object">' .
                    Callback::toString($id) . ($id instanceof Closure ? $shortFilename(Callback::toReflection($id)) : '') .
                    '</span></span></th></tr>';

                continue;
            }

            $class = array_search($id, $registeredClasses, TRUE);
            if (!$this->sl->isCreated($id) && $class !== FALSE) {
                $classRefl = new ReflectionClass($class);

                $s .= '<tr><td width=18>>&nbsp;</td><td><pre class="nette-dump"><span class="nette-dump-object">' .
                    $h($classRefl->getName()) .
                    '</span></span></th></tr>';
            } else {
                try {
                    $s .= '<tr><td width=18>>&nbsp;</td><td>' . self::dumpToHtml($this->sl->getService($id)) . '</th></tr>';
                } catch (\Exception $e) {
                    $s .= sprintf("<tr><td colspan=2>Service %s cannot be loaded because of exception<br><br>\n%s</td></th>", $id, (string) $e);
                }
            }
        }

        return $s;
    }

    private static function dumpToHtml($structure)
    {
        return Dumper::toHtml($structure, [Dumper::COLLAPSE => TRUE, Dumper::DEPTH => 2]);
    }

    private function getClassMap()
    {
        if ($this->registeredClasses !== NULL) {
            return $this->registeredClasses;
        }

        $refl = new ReflectionProperty(DIContainer::class, 'aliases');
        $refl->setAccessible(TRUE);
        $types = $refl->getValue($this->sl);

        $this->registeredClasses = [];
        foreach ($types as $type => $serviceIds) {
            if (isset($this->registeredClasses[$type])) {
                $this->registeredClasses[$type] = FALSE;
                continue;
            }

            $this->registeredClasses[$type] = $serviceIds;
        }

        return $this->registeredClasses;
    }

    private function renderCalls(array $calls)
    {
        $s = '';
        foreach ($calls as $args) {
            $s .= '<tr><td width=18>&nbsp;</td>';
            $s .= '<td' . ($args ? ' id="' . $this->formatArgsId($args) . '">' . self::dumpToHtml($args) : '>dispatched without arguments');
            $s .= '</td></tr>';
        }

        return $s;
    }

    /**
     * @param string $name
     * @return string
     */
    private function formatEventId($name)
    {
        return 'event-' . md5($name);
    }

    /**
     * @param object $args
     * @return string
     */
    private function formatArgsId($args)
    {
        return 'event-arg-' . md5(spl_object_hash($args));
    }

    /**
     * @return string
     */
    protected function renderStyles()
    {
        return <<<CSS
                    #nette-debug .nette-panel .nette-KdybyEventsPanel,
                    #tracy-debug .tracy-panel .nette-KdybyEventsPanel { width: 670px !important;  }
                    #nette-debug .nette-panel .nette-KdybyEventsPanel table,
                    #tracy-debug .tracy-panel .nette-KdybyEventsPanel table { width: 655px !important; }
                    #nette-debug .nette-panel .nette-KdybyEventsPanel table th,
                    #tracy-debug .tracy-panel .nette-KdybyEventsPanel table th { font-size: 16px; }
                    #nette-debug .nette-panel .nette-KdybyEventsPanel table tr td:first-child,
                    #tracy-debug .tracy-panel .nette-KdybyEventsPanel table tr td:first-child { padding-bottom: 0; }
                    #nette-debug .nette-panel .nette-KdybyEventsPanel table tr.blank td,
                    #tracy-debug .tracy-panel .nette-KdybyEventsPanel table tr.blank td { background: white; height:25px; border-left:0; border-right:0; }
                    #nette-debug .nette-panel .nette-KdybyEventsPanel table tr td ul,
                    #tracy-debug .tracy-panel .nette-KdybyEventsPanel table tr td ul { background: url(data:image/gif;base64,R0lGODlhCQAJAIABAIODg////yH5BAEAAAEALAAAAAAJAAkAAAIPjI8GebDsHopSOVgb26EAADs=) 0 5px no-repeat; padding-left: 12px; list-style-type: none; }
CSS;
    }

    public static function register(EventManager $eventManager, DIContainer $sl): Panel
    {
        /** @var \Kdyby\Events\Diagnostics\Panel $panel */
        $panel = new Panel($sl);
        $panel->setEventManager($eventManager);
        Debugger::getBar()->addPanel($panel);

        return $panel;
    }

}
