<?php

namespace Tests\Integration\Wikibase\InternalSerialization;

use Wikibase\DataModel\Entity\Item;
use Wikibase\InternalSerialization\SerializerFactory;

/**
 * @covers Wikibase\InternalSerialization\SerializerFactory
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class SerializerFactoryTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @var SerializerFactory
	 */
	private $factory;

	protected function setUp() {
		$this->factory = new SerializerFactory( $this->getMock( 'Serializers\Serializer' ) );
	}

	public function testEntitySerializerConstruction() {
		$this->factory->newEntitySerializer()->serialize( Item::newEmpty() );

		$this->assertTrue(
			true,
			'The serializer returned by newEntitySerializer can serialize an Item'
		);
	}

}
