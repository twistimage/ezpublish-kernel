<?php

/**
 * File containing the UrlAliasRouter class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace eZ\Publish\Core\MVC\Symfony\Routing;

use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\URLAliasService;
use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\Values\Content\URLAlias;
use eZ\Publish\API\Repository\Exceptions\NotFoundException;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\Core\MVC\Symfony\View\Manager as ViewManager;
use eZ\Publish\Core\MVC\Symfony\Routing\Generator\UrlAliasGenerator;
use Symfony\Cmf\Component\Routing\ChainedRouterInterface;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use InvalidArgumentException;
use LogicException;

class UrlAliasRouter implements ChainedRouterInterface, RequestMatcherInterface
{
    const URL_ALIAS_ROUTE_NAME = 'ez_urlalias';

    const LOCATION_VIEW_CONTROLLER = 'ez_content:viewLocation';

    /**
     * @var \Symfony\Component\Routing\RequestContext
     */
    protected $requestContext;

    /**
     * @var \eZ\Publish\API\Repository\LocationService
     */
    protected $locationService;

    /**
     * @var \eZ\Publish\API\Repository\URLAliasService
     */
    protected $urlAliasService;

    /**
     * @var \eZ\Publish\API\Repository\ContentService
     */
    protected $contentService;

    /**
     * @var \eZ\Publish\Core\MVC\Symfony\Routing\Generator\UrlAliasGenerator
     */
    protected $generator;

    /**
     * Holds current root Location id.
     *
     * @var int|string
     */
    protected $rootLocationId;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(
        LocationService $locationService,
        URLAliasService $urlAliasService,
        ContentService $contentService,
        UrlAliasGenerator $generator,
        RequestContext $requestContext,
        LoggerInterface $logger = null
    ) {
        $this->locationService = $locationService;
        $this->urlAliasService = $urlAliasService;
        $this->contentService = $contentService;
        $this->generator = $generator;
        $this->requestContext = $requestContext !== null ? $requestContext : new RequestContext();
        $this->logger = $logger;
    }

    /**
     * Injects current root Location id.
     *
     * @param int|string $rootLocationId
     */
    public function setRootLocationId($rootLocationId)
    {
        $this->rootLocationId = $rootLocationId;
    }

    /**
     * Tries to match a request with a set of routes.
     *
     * If the matcher can not find information, it must throw one of the exceptions documented
     * below.
     *
     * @param Request $request The request to match
     *
     * @return array An array of parameters
     *
     * @throws \Symfony\Component\Routing\Exception\ResourceNotFoundException If no matching resource could be found
     */
    public function matchRequest(Request $request)
    {
        try {
            $requestedPath = rawurldecode($request->attributes->get('semanticPathinfo', $request->getPathInfo()));
            $urlAlias = $this->getUrlAlias($requestedPath);
            if ($this->rootLocationId === null) {
                $pathPrefix = '/';
            } else {
                $pathPrefix = $this->generator->getPathPrefixByRootLocationId($this->rootLocationId);
            }

            $params = array(
                '_route' => self::URL_ALIAS_ROUTE_NAME,
            );
            switch ($urlAlias->type) {
                case URLAlias::LOCATION:
                    $params += array(
                        '_controller' => static::LOCATION_VIEW_CONTROLLER,
                        'locationId' => $urlAlias->destination,
                        'viewType' => ViewManager::VIEW_TYPE_FULL,
                        'layout' => true,
                    );

                    $request->attributes->set('locationId', $urlAlias->destination);

                    // For Location alias setup 301 redirect to Location's current URL when:
                    // 1. alias is history
                    // 2. alias is custom with forward flag true
                    // 3. requested URL is not case-sensitive equal with the one loaded
                    if ($urlAlias->isHistory === true || ($urlAlias->isCustom === true && $urlAlias->forward === true)) {
                        $request->attributes->set(
                            'semanticPathinfo',
                            $this->generate(
                                $this->generator->loadLocation($urlAlias->destination)
                            )
                        );
                        $request->attributes->set('needsRedirect', true);
                        // Specify not to prepend siteaccess while redirecting when applicable since it would be already present (see UrlAliasGenerator::doGenerate())
                        $request->attributes->set('prependSiteaccessOnRedirect', false);
                    } elseif ($this->needsCaseRedirect($urlAlias, $requestedPath, $pathPrefix)) {
                        $request->attributes->set('semanticPathinfo', $this->removePathPrefix($urlAlias->path, $pathPrefix));
                        $request->attributes->set('needsRedirect', true);
                    }

                    if (isset($this->logger)) {
                        $this->logger->info("UrlAlias matched location #{$urlAlias->destination}. Forwarding to ViewController");
                    }

                    break;

                case URLAlias::RESOURCE:
                    // In URLAlias terms, "forward" means "redirect".
                    if ($urlAlias->forward) {
                        $request->attributes->set('semanticPathinfo', '/' . trim($urlAlias->destination, '/'));
                        $request->attributes->set('needsRedirect', true);
                    } elseif ($this->needsCaseRedirect($urlAlias, $requestedPath, $pathPrefix)) {
                        // Handle case-correction redirect
                        $request->attributes->set('semanticPathinfo', $this->removePathPrefix($urlAlias->path, $pathPrefix));
                        $request->attributes->set('needsRedirect', true);
                    } else {
                        $request->attributes->set('semanticPathinfo', '/' . trim($urlAlias->destination, '/'));
                        $request->attributes->set('needsForward', true);
                    }

                    break;

                case URLAlias::VIRTUAL:
                    // Handle case-correction redirect
                    if ($this->needsCaseRedirect($urlAlias, $requestedPath, $pathPrefix)) {
                        $request->attributes->set('semanticPathinfo', $this->removePathPrefix($urlAlias->path, $pathPrefix));
                        $request->attributes->set('needsRedirect', true);
                    } else {
                        // Virtual aliases should load the Content at homepage URL
                        $request->attributes->set('semanticPathinfo', '/');
                        $request->attributes->set('needsForward', true);
                    }

                    break;
            }

            return $params;
        } catch (NotFoundException $e) {
            throw new ResourceNotFoundException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Removes prefix from path.
     *
     * Checks for presence of $prefix and removes it from $path if found.
     *
     * @param string $path
     * @param string $prefix
     *
     * @return string
     */
    protected function removePathPrefix($path, $prefix)
    {
        if ($prefix !== '/' && mb_stripos($path, $prefix) === 0) {
            $path = mb_substr($path, mb_strlen($prefix));
        }

        return $path;
    }

    /**
     * Returns true of false on comparing $urlAlias->path and $path with case sensitivity.
     *
     * Used to determine if redirect is needed because requested path is case-different
     * from the stored one.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\URLAlias $loadedUrlAlias
     * @param string $requestedPath
     * @param string $pathPrefix
     *
     * @return bool
     */
    protected function needsCaseRedirect(URLAlias $loadedUrlAlias, $requestedPath, $pathPrefix)
    {
        // If requested path is excluded from tree root jail, compare it to loaded UrlAlias directly.
        if ($this->generator->isUriPrefixExcluded($requestedPath)) {
            return strcmp($loadedUrlAlias->path, $requestedPath) !== 0;
        }

        // Compare loaded UrlAlias with requested path, prefixed with configured path prefix.
        return (
            strcmp(
                $loadedUrlAlias->path,
                $pathPrefix . ($pathPrefix === '/' ? trim($requestedPath, '/') : rtrim($requestedPath, '/'))
            ) !== 0
        );
    }

    /**
     * Returns the UrlAlias object to use, starting from the request.
     *
     * @param $pathinfo
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if the path does not exist or is not valid for the given language
     *
     * @return URLAlias
     */
    protected function getUrlAlias($pathinfo)
    {
        return $this->urlAliasService->lookup($pathinfo);
    }

    /**
     * Gets the RouteCollection instance associated with this Router.
     *
     * @return RouteCollection A RouteCollection instance
     */
    public function getRouteCollection()
    {
        return new RouteCollection();
    }

    /**
     * Generates a URL for a location, from the given parameters.
     *
     * It is possible to directly pass a Location object as the route name, as the ChainRouter allows it through ChainedRouterInterface.
     *
     * If $name is a route name, the "location" key in $parameters must be set to a valid eZ\Publish\API\Repository\Values\Content\Location object.
     * "locationId" can also be provided.
     *
     * If the generator is not able to generate the url, it must throw the RouteNotFoundException
     * as documented below.
     *
     * @see UrlAliasRouter::supports()
     *
     * @param string|\eZ\Publish\API\Repository\Values\Content\Location $name The name of the route or a Location instance
     * @param mixed $parameters An array of parameters
     * @param bool $absolute Whether to generate an absolute URL
     *
     * @throws \LogicException
     * @throws \Symfony\Component\Routing\Exception\RouteNotFoundException
     * @throws \InvalidArgumentException
     *
     * @return string The generated URL
     *
     * @api
     */
    public function generate($name, $parameters = array(), $absolute = false)
    {
        // Direct access to Location
        if ($name instanceof Location) {
            return $this->generator->generate($name, $parameters, $absolute);
        }

        // Normal route name
        if ($name === self::URL_ALIAS_ROUTE_NAME) {
            if (isset($parameters['location']) || isset($parameters['locationId'])) {
                // Check if location is a valid Location object
                if (isset($parameters['location']) && !$parameters['location'] instanceof Location) {
                    throw new LogicException(
                        "When generating an UrlAlias route, 'location' parameter must be a valid eZ\\Publish\\API\\Repository\\Values\\Content\\Location."
                    );
                }

                $location = isset($parameters['location']) ? $parameters['location'] : $this->locationService->loadLocation($parameters['locationId']);
                unset($parameters['location'], $parameters['locationId'], $parameters['viewType'], $parameters['layout']);

                return $this->generator->generate($location, $parameters, $absolute);
            }

            if (isset($parameters['contentId'])) {
                $contentInfo = $this->contentService->loadContentInfo($parameters['contentId']);
                unset($parameters['contentId'], $parameters['viewType'], $parameters['layout']);

                if (empty($contentInfo->mainLocationId)) {
                    throw new LogicException('Cannot generate an UrlAlias route for content without main location.');
                }

                return $this->generator->generate(
                    $this->locationService->loadLocation($contentInfo->mainLocationId),
                    $parameters,
                    $absolute
                );
            }

            throw new InvalidArgumentException(
                "When generating an UrlAlias route, either 'location', 'locationId' or 'contentId' must be provided."
            );
        }

        throw new RouteNotFoundException('Could not match route');
    }

    public function setContext(RequestContext $context)
    {
        $this->requestContext = $context;
        $this->generator->setRequestContext($context);
    }

    public function getContext()
    {
        return $this->requestContext;
    }

    /**
     * Not supported. Please use matchRequest() instead.
     *
     * @param $pathinfo
     *
     * @throws \RuntimeException
     */
    public function match($pathinfo)
    {
        throw new \RuntimeException("The UrlAliasRouter doesn't support the match() method. Please use matchRequest() instead.");
    }

    /**
     * Whether the router supports the thing in $name to generate a route.
     *
     * This check does not need to look if the specific instance can be
     * resolved to a route, only whether the router can generate routes from
     * objects of this class.
     *
     * @param mixed $name The route name or route object
     *
     * @return bool
     */
    public function supports($name)
    {
        return $name instanceof Location || $name === self::URL_ALIAS_ROUTE_NAME;
    }

    /**
     * @see Symfony\Cmf\Component\Routing\VersatileGeneratorInterface::getRouteDebugMessage()
     */
    public function getRouteDebugMessage($name, array $parameters = array())
    {
        if ($name instanceof RouteObjectInterface) {
            return 'Route with key ' . $name->getRouteKey();
        }

        if ($name instanceof SymfonyRoute) {
            return 'Route with pattern ' . $name->getPath();
        }

        return $name;
    }
}
