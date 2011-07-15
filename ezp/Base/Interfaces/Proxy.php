<?php
/**
 * File contains Interface for Proxy object
 *
 * @copyright Copyright (C) 1999-2011 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace ezp\Base\Interfaces;

/**
 * Interface for Proxy object
 *
 */
interface Proxy
{
    /**
     * Load the object this proxy object represent
     *
     * @return \ezp\Base\AbstractModel
     */
    public function load();
}
