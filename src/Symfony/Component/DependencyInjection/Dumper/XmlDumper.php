<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Dumper;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\InterfaceInjector;

/**
 * XmlDumper dumps a service container as an XML string.
 *
 * @author Fabien Potencier <fabien.potencier@symfony-project.com>
 * @author Martin Hasoň <martin.hason@gmail.com>
 */
class XmlDumper extends Dumper
{
    /**
     * @var \DOMDocument
     */
    protected $document;

    /**
     * Dumps the service container as an XML string.
     *
     * @param  array  $options An array of options
     *
     * @return string An xml string representing of the service container
     */
    public function dump(array $options = array())
    {
        $this->document = new \DOMDocument('1.0', 'utf-8');
        $this->document->formatOutput = true;

        $container = $this->document->createElementNS('http://www.symfony-project.org/schema/dic/services', 'container');
        $container->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $container->setAttribute('xsi:schemaLocation', 'http://www.symfony-project.org/schema/dic/services http://www.symfony-project.org/schema/dic/services/services-1.0.xsd');

        $this->addScopes($container);
        $this->addParameters($container);
        $this->addServices($container);
        $this->addInterfaceInjectors($container);

        $this->document->appendChild($container);
        $xml = $this->document->saveXML();
        $this->document = null;

        return $xml;
    }

    protected function addScopes(\DOMElement $parent)
    {
        $scopes = $this->container->getScopes();
        $levels = $this->container->getLevels();

        $node = $this->document->createElement('scopes');
        $parent->appendChild($node);

        foreach ($scopes as $scopeName => $scope) {
            $this->addScope($node, $scopeName, $scope, isset($levels[$scopeName]) ? $levels[$scopeName] : null);
        }
    }

    protected function addScope(\DOMElement $parent, $scopeName, $scope, $level = null)
    {
        $node = $this->document->createElement('scope');
        $node->setAttribute('name', $scopeName);
        $node->setAttribute('class', get_class($scope));
        if (null !== $level) {
            $node->setAttribute('level', $level);
        }
        $parent->appendChild($node);
    }

    protected function addParameters(\DOMElement $parent)
    {
        $data = $this->container->getParameterBag()->all();
        if (!$data) {
            return;
        }

        if ($this->container->isFrozen()) {
            $data = $this->escape($data);
        }

        $parameters = $this->document->createElement('parameters');
        $parent->appendChild($parameters);
        $this->convertParameters($data, 'parameter', $parameters);
    }

    protected function addMethodCalls(array $methodcalls, \DOMElement $parent)
    {
        foreach ($methodcalls as $methodcall) {
            $call = $this->document->createElement('call');
            $call->setAttribute('method', $methodcall[0]);
            if (count($methodcall[1])) {
                $this->convertParameters($methodcall[1], 'argument', $call);
            }
            $parent->appendChild($call);
        }
    }

    protected function addInterfaceInjector(InterfaceInjector $injector, \DOMElement $parent)
    {
        $interface = $this->document->createElement('interface');
        $interface->setAttribute('class', $injector->getClass());
        $this->addMethodCalls($injector->getMethodCalls(), $interface);
        $parent->appendChild($interface);
    }

    protected function addInterfaceInjectors(\DOMElement $parent)
    {
        if (!$this->container->getInterfaceInjectors()) {
            return;
        }

        $interfaces = $this->document->createElement('interfaces');
        foreach ($this->container->getInterfaceInjectors() as $injector) {
            $this->addInterfaceInjector($injector, $interfaces);
        }
        $parent->appendChild($interfaces);
    }

    protected function addService($definition, $id, \DOMElement $parent)
    {
        $service = $this->document->createElement('service');
        if (null !== $id) {
            $service->setAttribute('id', $id);
        }
        if ($definition->getClass()) {
            $service->setAttribute('class', $definition->getClass());
        }
        if ($definition->getFactoryMethod()) {
            $service->setAttribute('factory-method', $definition->getFactoryMethod());
        }
        if ($definition->getFactoryService()) {
            $service->setAttribute('factory-service', $definition->getFactoryService());
        }
        if ($definition->getScope()) {
            $service->setAttribute('scope', $definition->getScope());
        }

        foreach ($definition->getTags() as $name => $tags) {
            foreach ($tags as $attributes) {
                $tag = $this->document->createElement('tag');
                $tag->setAttribute('name', $name);
                foreach ($attributes as $key => $value) {
                    $tag->setAttribute($key, $value);
                }
                $service->appendChild($tag);
            }
        }

        if ($definition->getFile()) {
            $file = $this->document->createElement('file', $definition->getFile());
            $service->appendChild($file);
        }

        if ($parameters = $definition->getArguments()) {
            $this->convertParameters($parameters, 'argument', $service);
        }

        $this->addMethodCalls($definition->getMethodCalls(), $service);

        if ($callable = $definition->getConfigurator()) {
            $configurator = $this->document->createElement('configurator');
            if (is_array($callable)) {
                $configurator->setAttribute((is_object($callable[0]) && $callable[0] instanceof Reference ? 'service' : 'class'), $callable[0]);
                $configurator->setAttribute('method', $callable[1]);
            } else {
                $configurator->setAttribute('function', $callable);
            }
            $service->appendChild($configurator);
        }

        $parent->appendChild($service);
    }

    protected function addServiceAlias($alias, $id, \DOMElement $parent)
    {
        $service = $this->document->createElement('service');
        $service->setAttribute('id', $alias);
        $service->setAttribute('alias', $id);
        if (!$id->isPublic()) {
            $service->setAttribute('public', 'false');
        }
        $parent->appendChild($service);
    }

    protected function addServices(\DOMElement $parent)
    {
        $definitions = $this->container->getDefinitions();
        if (!$definitions) {
            return;
        }

        $services = $this->document->createElement('services');
        foreach ($definitions as $id => $definition) {
            $this->addService($definition, $id, $services);
        }

        foreach ($this->container->getAliases() as $alias => $id) {
            $this->addServiceAlias($alias, $id, $services);
        }
        $parent->appendChild($services);
    }

    protected function convertParameters($parameters, $type, \DOMElement $parent)
    {
        $withKeys = array_keys($parameters) !== range(0, count($parameters) - 1);
        foreach ($parameters as $key => $value) {
            $element = $this->document->createElement($type);
            if ($withKeys) {
                $element->setAttribute('key', $key);
            }

            if (is_array($value)) {
                $element->setAttribute('type', 'collection');
                $this->convertParameters($value, $type, $element);
            } else if (is_object($value) && $value instanceof Reference) {
                $element->setAttribute('type', 'service');
                $element->setAttribute('id', (string) $value);
                $behaviour = $value->getInvalidBehavior();
                if ($behaviour == ContainerInterface::NULL_ON_INVALID_REFERENCE)
                    $element->setAttribute('on-invalid', 'null');
                else if ($behaviour == ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
                    $element->setAttribute('on-invalid', 'ignore');
            } else if (is_object($value) && $value instanceof Definition) {
                $element->setAttribute('type', 'service');
                $this->addService($value, null, $element);
            } else {
                if (in_array($value, array('null', 'true', 'false'), true)) {
                    $element->setAttribute('type', 'string');
                }
                $text = $this->document->createTextNode(self::phpToXml($value));
                $element->appendChild($text);
            }
            $parent->appendChild($element);
        }
    }

    protected function escape($arguments)
    {
        $args = array();
        foreach ($arguments as $k => $v) {
            if (is_array($v)) {
                $args[$k] = $this->escape($v);
            } elseif (is_string($v)) {
                $args[$k] = str_replace('%', '%%', $v);
            } else {
                $args[$k] = $v;
            }
        }

        return $args;
    }

    /**
     * @throws \RuntimeException When trying to dump object or resource
     */
    static public function phpToXml($value)
    {
        switch (true) {
            case null === $value:
                return 'null';
            case true === $value:
                return 'true';
            case false === $value:
                return 'false';
            case is_object($value) && $value instanceof Parameter:
                return '%'.$value.'%';
            case is_object($value) || is_resource($value):
                throw new \RuntimeException('Unable to dump a service container if a parameter is an object or a resource.');
            default:
                return (string) $value;
        }
    }
}
