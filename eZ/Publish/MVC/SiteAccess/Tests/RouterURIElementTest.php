<?php
/**
 * File containing the eZ\Publish\MVC\SiteAccess\Tests\RouterURIElementTest class
 *
 * @copyright Copyright (C) 1999-2012 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\MVC\SiteAccess\Tests;
use PHPUnit_Framework_TestCase,
    eZ\Publish\MVC\SiteAccess\Router,
    eZ\Publish\MVC\SiteAccess\Matcher\URIElement as URIElementMatcher;

class RouterURIElementTest extends PHPUnit_Framework_TestCase
{
    /**
     * @covers \eZ\Publish\MVC\SiteAccess\Router::__construct
     */
    public function testConstruct()
    {
        return new Router(
            "default_sa",
            array(
                "URIElement" => 1,
                "Map\\URI" => array(
                    "first_sa" => "first_sa",
                    "second_sa" => "second_sa",
                ),
                "Map\\Host" => array(
                    "first_sa" => "first_sa",
                    "first_siteaccess" => "first_sa",
                ),
            )
        );
    }

    /**
     * @depends testConstruct
     * @dataProvider matchProvider
     * @covers \eZ\Publish\MVC\SiteAccess\Router::match
     * @covers \eZ\Publish\MVC\SiteAccess\Matcher\Map::__construct
     * @covers \eZ\Publish\MVC\SiteAccess\Matcher\Map::match
     * @covers \eZ\Publish\MVC\SiteAccess\Matcher\Map\URI::__construct
     * @covers \eZ\Publish\MVC\SiteAccess\Matcher\Map\Host::__construct
     * @covers \eZ\Publish\MVC\SiteAccess\Matcher\URIElement::__construct
     * @covers \eZ\Publish\MVC\SiteAccess\Matcher\URIElement::match
     */
    public function testMatch( $url, $siteAccess, $router )
    {
        $sa = $router->match( $url );
        $this->assertInstanceOf( 'eZ\\Publish\\MVC\\SiteAccess', $sa );
        $this->assertSame( $siteAccess, $sa->name );
    }

    public function matchProvider()
    {
        return array(
            array( "http://example.com", "default_sa" ),
            array( "https://example.com", "default_sa" ),
            array( "http://example.com/", "default_sa" ),
            array( "https://example.com/", "default_sa" ),
            array( "http://example.com//", "default_sa" ),
            array( "https://example.com//", "default_sa" ),
            array( "http://example.com:8080/", "default_sa" ),
            array( "http://example.com/first_siteaccess/", "first_siteaccess" ),
            array( "http://example.com/?first_siteaccess", "default_sa" ),
            array( "http://example.com/?first_sa", "default_sa" ),
            array( "http://example.com/first_salt", "first_salt" ),
            array( "http://example.com/first_sa.foo", "first_sa.foo" ),
            array( "http://example.com/test", "test" ),
            array( "http://example.com/test/foo/", "test" ),
            array( "http://example.com/test/foo/bar/", "test" ),
            array( "http://example.com/test/foo/bar/first_sa", "test" ),
            array( "http://example.com/default_sa", "default_sa" ),

            array( "http://example.com/first_sa", "first_sa" ),
            array( "http://example.com/first_sa/", "first_sa" ),
            // Double slashes shouldn't be considered as one
            array( "http://example.com//first_sa//", "default_sa" ),
            array( "http://example.com///first_sa///test", "default_sa" ),
            array( "http://example.com//first_sa//foo/bar", "default_sa" ),
            array( "http://example.com/first_sa/foo", "first_sa" ),
            array( "http://example.com:82/first_sa/", "first_sa" ),
            array( "http://third_siteaccess/first_sa/", "first_sa" ),
            array( "http://first_sa/", "first_sa" ),
            array( "https://first_sa/", "first_sa" ),
            array( "http://first_sa:81/", "first_sa" ),
            array( "http://first_siteaccess/", "first_sa" ),
            array( "http://first_siteaccess:82/", "first_sa" ),
            array( "http://first_siteaccess:83/", "first_sa" ),
            array( "http://first_siteaccess/foo/", "foo" ),
            array( "http://first_siteaccess:82/foo/", "foo" ),
            array( "http://first_siteaccess:83/foo/", "foo" ),

            array( "http://example.com/second_sa", "second_sa" ),
            array( "http://example.com/second_sa/", "second_sa" ),
            array( "http://example.com/second_sa?param1=foo", "second_sa" ),
            array( "http://example.com/second_sa/foo/", "second_sa" ),
            array( "http://example.com:82/second_sa/", "second_sa" ),
            array( "http://example.com:83/second_sa/", "second_sa" ),
            array( "http://first_siteaccess:82/second_sa/", "second_sa" ),
            array( "http://first_siteaccess:83/second_sa/", "second_sa" ),
        );
    }

    /**
     * @covers \eZ\Publish\MVC\SiteAccess\Matcher\URIElement
     */
    public function testGetName()
    {
        $matcher = new URIElementMatcher( array(), array() );
        $this->assertSame( 'uri:element', $matcher->getName() );
    }
}