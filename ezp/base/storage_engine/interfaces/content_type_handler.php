<?php
/**
 * File contains ContentType Handler Storage Engine Interface
 *
 * @copyright Copyright (C) 1999-2011 eZ Systems AS. All rights reserved.
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2.0
 * @package ezp
 * @subpackage base
 */

/**
 * ContentType Handler Storage Engine Interface
 *
 * @package ezp
 * @subpackage base
 */
namespace ezp\base\StorageEngine;
interface ContentTypeHandlerInterface extends HandlerInterface
{
    /**
     * Create ContentType object
     *
     * @param \ezp\content\ContentType $contentType
     * @return \ezp\content\ContentType
     */
    public function create( \ezp\content\ContentType $contentType );

    /**
     * Get ContentType object by id
     *
     * @param int $id
     * @return \ezp\content\ContentType
     */
    public function load( $id );

    /**
     * Get ContentType object by identifier
     *
     * @param string $identifier
     * @return \ezp\content\ContentType
     */
    public function loadByIdentifier( $identifier );
}
