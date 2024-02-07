<?php

namespace DekAppsTest;

use DekApps\Evm\Evm;
use DekAppsTests\Subscribers\FirstTestSubscriber;
use Doctrine\Common\EventManager;
use Nette\Configurator;
use Nette\DI\Container;
use Tester\Assert;
use Tester\TestCase;

require_once __DIR__ . '/bootstrap.php';

class EvmTest extends TestCase
{
    protected Container $container;

    protected function setUp()
    {
        $files=[
            __DIR__ . '/config/first.neon',
        ];

        $rootDir = __DIR__ . '/..';

        $config = new Configurator();
        $config->setTempDirectory(TEMP_DIR)
            ->addConfig(__DIR__ . '/nette-reset.neon')
            ->addParameters([
                'appDir' => $rootDir,
                'wwwDir' => $rootDir,
        ]);

        foreach ($files as $file) {
            $config->addConfig($file);
        }

        /** @var Nette\DI\Container $container */
        $this->container = $config->createContainer();
    }

    public function testSubscriber()
    {
        $evmsname = 'dekApps.eventManager.evm';
        $evm = $this->container->getService($evmsname);
        Assert::equal($this->container->isCreated($evmsname),true);
        Assert::equal(Evm::class, get_class($evm));
        $evm = $this->container->getByType(EventManager::class);
        Assert::equal(Evm::class, get_class($evm));
        
        
        $evm->dispatchEvent('aEvent');
        $subscriber = $this->container->getByType(FirstTestSubscriber::class);
        Assert::equal($subscriber->aEventCalled, true);
        Assert::equal($subscriber->bEventCalled, false);
        $evm->dispatchEvent('bEvent');
        Assert::equal($subscriber->aEventCalled, true);
        Assert::equal($subscriber->bEventCalled, true);
        
    }

}

(new EvmTest())->run();
