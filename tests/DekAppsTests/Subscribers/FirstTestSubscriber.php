<?php
declare(strict_types = 1);

namespace DekAppsTests\Subscribers;

use Doctrine\Common\EventSubscriber;

class FirstTestSubscriber implements EventSubscriber
{
    public bool $aEventCalled = false;
    public bool $bEventCalled = false;

    public function getSubscribedEvents(): array
    {
        return [
            'aEvent',
            'bEvent',
        ];
    }

    public function aEvent(): void
    {
        $this->aEventCalled = true;
    }

    public function bEvent(): void
    {
        $this->bEventCalled = true;
    }

}
