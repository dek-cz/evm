<?php
declare(strict_types = 1);

namespace DekApps\Evm;

use DekApps\Evm\Diagnostics\Panel;
use Doctrine\Common\EventArgs;
use Doctrine\Common\EventManager;
use Doctrine\Common\EventSubscriber;
use Nette\DI\Container;

class Evm extends EventManager
{

    /**
     * Map of registered listeners.
     *
     * <event> => <listeners>
     */
    private array $listeners = [];

    private array $initialized = [];

    private bool $initializedSubscribers = false;

    private array $initializedHashMapping = [];

    private array $methods = [];

    private Container $container;

    private ?Panel $panel = null;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function dispatchEvent(string $eventName, ?EventArgs $eventArgs = null): void
    {
        if ($this->panel) {
            $this->panel->eventDispatch($eventName, $eventArgs);
        }
        if (!$this->initializedSubscribers) {
            $this->initializeSubscribers();
        }
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        $eventArgs ??= EventArgs::getEmptyInstance();

        if (!isset($this->initialized[$eventName])) {
            $this->initializeListeners($eventName);
        }

        foreach ($this->listeners[$eventName] as $hash => $listener) {
            $listener->{$this->methods[$eventName][$hash]}($eventArgs);
        }
        if ($this->panel) {
            $this->panel->eventDispatched($eventName, $eventArgs);
        }
    }

    public function getListeners(string $event): array
    {
        if (!$this->initializedSubscribers) {
            $this->initializeSubscribers();
        }
        if (!isset($this->initialized[$event])) {
            $this->initializeListeners($event);
        }

        return $this->listeners[$event];
    }

    public function getAllListeners(): array
    {
        if (!$this->initializedSubscribers) {
            $this->initializeSubscribers();
        }

        foreach ($this->listeners as $event => $listeners) {
            if (!isset($this->initialized[$event])) {
                $this->initializeListeners($event);
            }
        }

        return $this->listeners;
    }

    public function hasListeners(string $event): bool
    {
        if (!$this->initializedSubscribers) {
            $this->initializeSubscribers();
        }

        return isset($this->listeners[$event]) && $this->listeners[$event];
    }

    public function addEventListener(string|array $events, object|string|array $listener): void
    {
        if (!$this->initializedSubscribers) {
            $this->initializeSubscribers();
        }

        $hash = $this->getHash($listener);

        foreach ((array) $events as $eventName) {
            $event = $eventName;
            if (is_array($listener) && count($listener) === 2) {
                $this->listeners[$event][$hash] = $listener[0];
                $this->methods[$event][$hash] = $this->getMethod($listener[0], $listener[1]);
            } else {
                // Overrides listener if a previous one was associated already
                // Prevents duplicate listeners on same event (same instance only)
                $this->listeners[$event][$hash] = $listener;
                if (\is_string($listener)) {
                    unset($this->initialized[$event]);
                    unset($this->initializedHashMapping[$event][$hash]);
                } else {
                    $this->methods[$event][$hash] = $this->getMethod($listener, $event);
                }
            }
        }
    }

    public function removeEventListener(string|array $events, object|string|array $listener): void
    {
        if (!$this->initializedSubscribers) {
            $this->initializeSubscribers();
        }

        $hash = $this->getHash($listener);

        foreach ((array) $events as $event) {
            if (isset($this->initializedHashMapping[$event][$hash])) {
                $hash = $this->initializedHashMapping[$event][$hash];
                unset($this->initializedHashMapping[$event][$hash]);
            }

            // Check if we actually have this listener associated
            if (isset($this->listeners[$event][$hash])) {
                unset($this->listeners[$event][$hash]);
            }

            if (isset($this->methods[$event][$hash])) {
                unset($this->methods[$event][$hash]);
            }
        }
    }

    public function addEventSubscriber(EventSubscriber $subscriber): void
    {
        if (!$this->initializedSubscribers) {
            $this->initializeSubscribers();
        }
        $subscribedEvents = $subscriber->getSubscribedEvents();
        $keys = array_keys($subscribedEvents);
        if ($keys === range(0, count($subscribedEvents) - 1)) {
            // sequential array
            $this->addEventListener($subscriber->getSubscribedEvents(), $subscriber);
        } else {
            foreach ($subscribedEvents as $eventName => $params) {
                $this->addEventListener($eventName, [$subscriber, $params]);
            }
        }
    }

    public function removeEventSubscriber(EventSubscriber $subscriber): void
    {
        if (!$this->initializedSubscribers) {
            $this->initializeSubscribers();
        }
        $subscribedEvents = $subscriber->getSubscribedEvents();
        $keys = array_keys($subscribedEvents);
        if ($keys === range(0, count($subscribedEvents) - 1)) {
            // sequential array
            $this->removeEventSubscriber($subscriber->getSubscribedEvents(), $subscriber);
        } else {

            foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
                $this->removeEventListener($eventName, [$subscriber, $params]);
            }
        }
    }

    private function initializeListeners(string $eventName): void
    {
        $this->initialized[$eventName] = true;

        // We'll refill the whole array in order to keep the same order
        $listeners = [];

        foreach ($this->listeners[$eventName] as $hash => $listener) {
            if (\is_string($listener)) {
                $listener = $this->container->getService($listener);
                $newHash = $this->getHash($listener);

                $this->initializedHashMapping[$eventName][$hash] = $newHash;

                $listeners[$newHash] = $listener;

                $this->methods[$eventName][$newHash] = $this->getMethod($listener, $eventName);
            } else {
                $listeners[$hash] = $listener;
            }
        }

        $this->listeners[$eventName] = $listeners;
    }

    private function initializeSubscribers(): void
    {
        $this->initializedSubscribers = true;
        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $listener) {
            if (\is_array($listener)) {
                $this->addEventListener(...$listener);
                continue;
            }

            throw new \InvalidArgumentException(sprintf('Using Doctrine subscriber "%s" is not allowed. Register it as a listener instead, using e.g. the #[AsDoctrineListener] or #[AsDocumentListener] attribute.', \is_object($listener) ? get_debug_type($listener) : $listener));
        }
    }

    private function getHash(string|object|array $listener): string
    {
        if (is_array($listener) && count($listener) === 2) {
            return spl_object_hash($listener[0]) . '_method_' . $listener[1];
        }
        if (\is_string($listener)) {
            return '_service_' . $listener;
        }

        return spl_object_hash($listener);
    }

    private function getMethod(object $listener, string $event): string
    {
        if (!method_exists($listener, $event) && method_exists($listener, '__invoke')) {
            return '__invoke';
        }

        return $event;
    }

    public function setPanel(?Panel $panel): void
    {
        $this->panel = $panel;
    }

}
