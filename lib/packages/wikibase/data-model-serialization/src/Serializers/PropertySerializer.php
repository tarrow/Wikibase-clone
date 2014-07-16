<?php

namespace Wikibase\DataModel\Serializers;

use Wikibase\DataModel\Entity\Entity;
use Wikibase\DataModel\Entity\Property;

/**
 * @since 0.1
 *
 * @licence GNU GPL v2+
 * @author Thomas Pellissier Tanon
 */
class PropertySerializer extends EntitySerializer {

	/**
	 * @see Serializer::isSerializerFor
	 *
	 * @param mixed $object
	 *
	 * @return bool
	 */
	public function isSerializerFor( $object ) {
		return $object instanceof Property;
	}

	protected function getSpecificSerialization( Entity $entity ) {
		/**
		 * @var Property $entity
		 */
		return array(
			'datatype' => $entity->getDataTypeId()
		);
	}

}
