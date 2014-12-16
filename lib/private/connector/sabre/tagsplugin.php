<?php

namespace OC\Connector\Sabre;

/**
 * ownCloud
 *
 * @author Vincent Petry
 * @copyright 2014 Vincent Petry <pvince81@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

class TagsPlugin extends \Sabre\DAV\ServerPlugin
{

	// namespace
	const NS_OWNCLOUD = 'http://owncloud.org/ns';
	const TAGS_PROPERTYNAME = '{' . self::NS_OWNCLOUD . '}tags';
	const FAVORITE_PROPERTYNAME = '{' . self::NS_OWNCLOUD . '}favorite';
	const TAG_FAVORITE = '_$!<Favorite>!$_';

	/**
	 * Reference to main server object
	 *
	 * @var \Sabre\DAV\Server
	 */
	private $server;

	/**
	 * @var \OCP\ITagManager
	 */
	private $tagManager;

	/**
	 * @var \OCP\ITags
	 */
	private $tagger;

	/**
	 * Array of file id to tags array
	 * The null value means the cache wasn't initialized.
	 *
	 * @var array
	 */
	private $cachedTags;

	/**
	 * Array of file id to favorite boolean.
	 * The null value means the cache wasn't initialized.
	 *
	 * @var array
	 */
	private $cachedFavorites;

	/**
	 * @param \OCP\ITagManager $tagManager tag manager
	 */
	public function __construct(\OCP\ITagManager $tagManager) {
		$this->tagManager = $tagManager;
		$this->tagger = null;
		$this->cachedTags = null;
		$this->cachedFavorites = null;
	}

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by \Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the required event subscriptions.
	 *
	 * @param \Sabre\DAV\Server $server
	 * @return void
	 */
	public function initialize(\Sabre\DAV\Server $server) {

		$server->xmlNamespaces[self::NS_OWNCLOUD] = 'oc';
		$server->propertyMap[self::TAGS_PROPERTYNAME] = 'OC\\Connector\\Sabre\\TagList';

		$this->server = $server;
		$this->server->subscribeEvent('beforeGetProperties', array($this, 'beforeGetProperties'));
		$this->server->subscribeEvent('updateProperties', array($this, 'updateProperties'));
	}

	/**
	 * Searches and removes a value from the given array
	 *
	 * @param array $requestedProps
	 * @param string $propName to remove
	 * @return boolean true if the property was present, false otherwise
	 */
	private function findAndRemoveProperty(&$requestedProps, $propName) {
		$index = array_search($propName, $requestedProps);
		if ($index !== false) {
			unset($requestedProps[$index]);
			return true;
		}
		return false;
	}

	/**
	 * Returns the tagger
	 *
	 * @return \OCP\ITags tagger
	 */
	private function getTagger() {
		if (!$this->tagger) {
			$this->tagger = $this->tagManager->load('files');
		}
		return $this->tagger;
	}

	/**
	 * Returns tags and favorites.
	 *
	 * @param int $fileId file id
	 * @return array list($tags, $favorite) with $tags as tag array
	 * and $favorite is a boolean whether the file was favorited
	 */
	private function getTagsAndFav($fileId) {
		$isFav = false;
		$tags = $this->getTagger()->getTagsForObjects(array($fileId));
		if ($tags) {
			$tags = current($tags);
			$favPos = array_search(self::TAG_FAVORITE, $tags);
			if ($favPos !== false) {
				$isFav = true;
				unset($tags[$favPos]);
			}
		}
		return array($tags, $isFav);
	}

	/**
	 * Updates the tags of the given file id
	 *
	 * @param int $fileId
	 * @param array $tags array of tag strings
	 */
	private function updateTags($fileId, $tags) {
		$tagger = $this->getTagger();
		$currentTags = $tagger->getTagsForObjects(array($fileId));

		if (!empty($currentTags)) {
			$currentTags = current($currentTags);
		}

		$newTags = array_diff($tags, $currentTags);
		foreach ($newTags as $tag) {
			if ($tag === self::TAG_FAVORITE) {
				continue;
			}
			$tagger->tagAs($fileId, $tag);
		}
		$deletedTags = array_diff($currentTags, $tags);
		foreach ($deletedTags as $tag) {
			if ($tag === self::TAG_FAVORITE) {
				continue;
			}
			$tagger->unTag($fileId, $tag);
		}
	}

	/**
	 * Adds tags and favorites properties to the response,
	 * if requested.
	 *
	 * @param string $path
	 * @param \Sabre\DAV\INode $node
	 * @param array $requestedProperties
	 * @param array $returnedProperties
	 * @return void
	 */
	public function beforeGetProperties(
		$path,
		\Sabre\DAV\INode $node,
		array &$requestedProperties,
		array &$returnedProperties
	) {
		if (!($node instanceof \OC_Connector_Sabre_Node)) {
			return;
		}

		$tags = null;
		$isFav = null;
		if ($this->findAndRemoveProperty($requestedProperties, self::TAGS_PROPERTYNAME)) {
			list($tags, $isFav) = $this->getTagsAndFav($node->getId());
			$returnedProperties[200][self::TAGS_PROPERTYNAME] = $tags = new TagList($tags);
		}
		if ($this->findAndRemoveProperty($requestedProperties, self::FAVORITE_PROPERTYNAME)) {
			if (is_null($tags)) {
				list($tags, $isFav) = $this->getTagsAndFav($node->getId());
			}
			$returnedProperties[200][self::FAVORITE_PROPERTYNAME] = $isFav;
		}
	}

	/**
	 * Updates tags and favorites properties, if applicable.
	 *
	 * @param string $path
	 * @param \Sabre\DAV\INode $node
	 * @param array $requestedProperties
	 * @param array $returnedProperties
	 * @return bool success status
	 */
	public function updateProperties(array &$properties, array &$result, \Sabre\DAV\INode $node) {
		if (!($node instanceof \OC_Connector_Sabre_Node)) {
			return;
		}

		$fileId = $node->getId();
		if (isset($properties[self::TAGS_PROPERTYNAME])) {
			$tagsProp = $properties[self::TAGS_PROPERTYNAME];
			unset($properties[self::TAGS_PROPERTYNAME]);
			$this->updateTags($fileId, $tagsProp->getTags());
			$result[200][self::TAGS_PROPERTYNAME] = new TagList($tagsProp->getTags());
		}
		if (isset($properties[self::FAVORITE_PROPERTYNAME])) {
			$favState = $properties[self::FAVORITE_PROPERTYNAME];
			unset($properties[self::FAVORITE_PROPERTYNAME]);
			if ((int)$favState === 1 || $favState === 'true') {
				$favState = true;
				$this->getTagger()->tagAs($fileId, self::TAG_FAVORITE);
			} else {
				$favState = false;
				$this->getTagger()->unTag($fileId, self::TAG_FAVORITE);
			}
			$result[200][self::FAVORITE_PROPERTYNAME] = $favState;
		}
		return true;
	}
}
