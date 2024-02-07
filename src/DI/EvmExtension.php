<?php

namespace DekApps\Evm\DI;

use DekApps\Evm\Evm;
use Nette\DI\CompilerExtension;
use Doctrine\Common\EventSubscriber;
use ReflectionClass;

class EvmExtension extends CompilerExtension
{

    const TAG_SUBSCRIBER = 'dekApps.eventManager.subscriber';

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        $builder->addDefinition($this->prefix('evm'))
            ->setType(Evm::class);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();

        $evm = $builder->getDefinition($this->prefix('evm'));
        foreach ($builder->findByTag(self::TAG_SUBSCRIBER) as $name => $attributes) {
            $class = $builder->getDefinition($name)->getClass();

            if ($class === null || !is_subclass_of($class, EventSubscriber::class)) {
                throw new AssertionException(
                        sprintf(
                            'Subscriber "%s" doesn\'t implement "%s".',
                            $name,
                            EventSubscriber::class
                        )
                );
            }
            $subscriber = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $evm->addSetup(
                '?->addEventListener(?, ?)',
                [
                    '@self',
                    $subscriber->getSubscribedEvents(),
                    $name,
                ]
            );
        }
    }

}
