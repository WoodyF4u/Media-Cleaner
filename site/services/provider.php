<?php

/**
 * @package     Media Cleaner
 * @subpackage  com_mediacleaner
 */

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Site (frontend) service provider. Lets a menu item be linked to this
 * component to show a read-only-by-default overview of the media files
 * (see Site\View\Files\HtmlView for the access checks); "Negeren" and
 * "Verwijderen" are only offered to logged-in users who have been
 * explicitly granted the matching "Site: ..." permission on the
 * component's Permissions tab - see admin/access.xml.
 */
return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     */
    public function register(Container $container)
    {
        $container->registerServiceProvider(new MVCFactory('\\Joomla\\Component\\Mediacleaner'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Joomla\\Component\\Mediacleaner'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new MVCComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            }
        );
    }
};
