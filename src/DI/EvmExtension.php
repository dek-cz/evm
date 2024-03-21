<?php
declare(strict_types = 1);

namespace DekApps\Evm\DI;

use DekApps\Evm\Diagnostics\Panel;
use DekApps\Evm\Evm;
use Doctrine\Common\EventSubscriber;
use Nette\DI\CompilerExtension;
use Nette\DI\Config\Helpers;
use Nette\PhpGenerator\ClassType as ClassTypeGenerator;
use Nette\PhpGenerator\Helpers as GeneratorHelpers;
use Nette\PhpGenerator\PhpLiteral;
use ReflectionClass;

class EvmExtension extends CompilerExtension
{

    const TAG_SUBSCRIBER = 'dekApps.eventManager.subscriber';

    const EVM_ALIAS = 'dekAppsEventManager';

    const PANEL_COUNT_MODE = 'count';

    public function loadConfiguration(): void
    {
        $config = $this->getConfig();
        $builder = $this->getContainerBuilder();
        $evmIsDef = $builder->hasDefinition(EvmExtension::EVM_ALIAS);
        if (!$evmIsDef) {
            $builder->addDefinition($this->prefix('evm'))
                ->setType(Evm::class);
            $builder->addAlias(self::EVM_ALIAS, $this->prefix('evm'));
        } else {
            $builder->addAlias($this->prefix('evm'), $builder->getDefinition(EvmExtension::EVM_ALIAS)->getName());
        }
        $evm = $builder->getDefinition($this->prefix('evm'));
        if ($config['debugger']) {
            $defaults = ['dispatchTree' => FALSE, 'dispatchLog' => TRUE, 'events' => TRUE, 'listeners' => FALSE];
            if (is_array($config['debugger'])) {
                $config['debugger'] = Helpers::merge($config['debugger'], $defaults);
            } else {
                $config['debugger'] = $config['debugger'] !== self::PANEL_COUNT_MODE;
            }

            $evm->addSetup('?::register(?, ?)->renderPanel = ?', [new PhpLiteral(Panel::class), '@self', '@container', $config['debugger']]);
        }
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
