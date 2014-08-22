<?php

namespace Wikibase\Test;

use DataValues\StringValue;
use Wikibase\DataModel\Claim\Claim;
use Wikibase\DataModel\Claim\Claims;
use Wikibase\DataModel\Claim\Statement;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\StatementList;

/**
 * @covers Wikibase\DataModel\Statement\StatementList
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class StatementListTest extends \PHPUnit_Framework_TestCase {

	public function testGivenNoStatements_getPropertyIdsReturnsEmptyArray() {
		$list = new StatementList();
		$this->assertSame( array(), $list->getPropertyIds() );
	}

	public function testGivenStatements_getPropertyIdsReturnsArrayWithoutDuplicates() {
		$list = new StatementList( array(
			$this->getStubStatement( 1, 'kittens' ),
			$this->getStubStatement( 3, 'foo' ),
			$this->getStubStatement( 2, 'bar' ),
			$this->getStubStatement( 2, 'baz' ),
			$this->getStubStatement( 1, 'bah' ),
		) );

		$this->assertEquals(
			array(
				'P1' => new PropertyId( 'P1' ),
				'P3' => new PropertyId( 'P3' ),
				'P2' => new PropertyId( 'P2' ),
			),
			$list->getPropertyIds()
		);
	}

	private function getStubStatement( $propertyId, $guid, $rank = Statement::RANK_NORMAL ) {
		$statement = $this->getMockBuilder( 'Wikibase\DataModel\Claim\Statement' )
			->disableOriginalConstructor()->getMock();

		$statement->expects( $this->any() )
			->method( 'getGuid' )
			->will( $this->returnValue( $guid ) );

		$statement->expects( $this->any() )
			->method( 'getPropertyId' )
			->will( $this->returnValue( PropertyId::newFromNumber( $propertyId ) ) );

		$statement->expects( $this->any() )
			->method( 'getRank' )
			->will( $this->returnValue( $rank ) );

		return $statement;
	}

	public function testCanIterate() {
		$statement = $this->getStubStatement( 1, 'kittens' );
		$list = new StatementList( array( $statement ) );

		foreach ( $list as $statementFormList ) {
			$this->assertEquals( $statement, $statementFormList );
		}
	}

	public function testGetBestStatementPerProperty() {
		$list = new StatementList( array(
			$this->getStubStatement( 1, 'one', Statement::RANK_PREFERRED ),
			$this->getStubStatement( 1, 'two', Statement::RANK_NORMAL ),
			$this->getStubStatement( 1, 'three', Statement::RANK_PREFERRED ),

			$this->getStubStatement( 2, 'four', Statement::RANK_DEPRECATED ),

			$this->getStubStatement( 3, 'five', Statement::RANK_DEPRECATED ),
			$this->getStubStatement( 3, 'six', Statement::RANK_NORMAL ),

			$this->getStubStatement( 4, 'seven', Statement::RANK_PREFERRED ),
			$this->getStubStatement( 4, 'eight', Statement::RANK_TRUTH ),
		) );

		$this->assertEquals(
			array(
				$this->getStubStatement( 1, 'one', Statement::RANK_PREFERRED ),
				$this->getStubStatement( 1, 'three', Statement::RANK_PREFERRED ),

				$this->getStubStatement( 3, 'six', Statement::RANK_NORMAL ),

				$this->getStubStatement( 4, 'eight', Statement::RANK_TRUTH ),
			),
			$list->getBestStatementPerProperty()->toArray()
		);
	}

	public function testGetUniqueMainSnaksReturnsListWithoutDuplicates() {
		$list = new StatementList( array(
			$this->getStatementWithSnak( 1, 'foo' ),
			$this->getStatementWithSnak( 2, 'foo' ),
			$this->getStatementWithSnak( 1, 'foo' ),
			$this->getStatementWithSnak( 2, 'bar' ),
			$this->getStatementWithSnak( 1, 'bar' ),
		) );

		$this->assertEquals(
			array(
				$this->getStatementWithSnak( 1, 'foo' ),
				$this->getStatementWithSnak( 2, 'foo' ),
				$this->getStatementWithSnak( 2, 'bar' ),
				$this->getStatementWithSnak( 1, 'bar' ),
			),
			array_values( $list->getWithUniqueMainSnaks()->toArray() )
		);
	}

	private function getStatementWithSnak( $propertyId, $stringValue ) {
		$snak = $this->newSnak( $propertyId, $stringValue );
		$claim = new Statement( $snak );
		$claim->setGuid( sha1( $snak->getHash() ) );
		return $claim;
	}

	private function newSnak( $propertyId, $stringValue ) {
		return new PropertyValueSnak( $propertyId, new StringValue( $stringValue ) );
	}

	public function testAddStatementWithOnlyMainSnak() {
		$list = new StatementList();

		$list->addNewStatement( $this->newSnak( 42, 'foo' ) );

		$this->assertEquals(
			new StatementList( array(
				new Statement( $this->newSnak( 42, 'foo' ) )
			) ),
			$list
		);
	}

	public function testAddStatementWithQualifiersAsSnakArray() {
		$list = new StatementList();

		$list->addNewStatement(
			$this->newSnak( 42, 'foo' ),
			array(
				$this->newSnak( 1, 'bar' )
			)
		);

		$this->assertEquals(
			new StatementList( array(
				new Statement(
					$this->newSnak( 42, 'foo' ),
					new SnakList( array(
						$this->newSnak( 1, 'bar' )
					) )
				)
			) ),
			$list
		);
	}

	public function testAddStatementWithQualifiersAsSnakList() {
		$list = new StatementList();
		$snakList = new SnakList( array(
			$this->newSnak( 1, 'bar' )
		) );

		$list->addNewStatement(
			$this->newSnak( 42, 'foo' ),
			$snakList
		);

		$this->assertEquals(
			new StatementList( array(
				new Statement(
					$this->newSnak( 42, 'foo' ),
					$snakList
				)
			) ),
			$list
		);
	}

	public function testAddStatementWithGuid() {
		$list = new StatementList();

		$list->addNewStatement(
			$this->newSnak( 42, 'foo' ),
			null,
			null,
			'kittens'
		);

		$statement = new Statement(
			$this->newSnak( 42, 'foo' ),
			null
		);

		$statement->setGuid( 'kittens' );

		$this->assertEquals(
			new StatementList( array( $statement ) ),
			$list
		);
	}

	public function testCanConstructWithClaimsObjectContainingOnlyStatements() {
		$statementArray = array(
			$this->getStatementWithSnak( 1, 'foo' ),
			$this->getStatementWithSnak( 2, 'bar' ),
		);

		$claimsObject = new Claims( $statementArray );

		$list = new StatementList( $claimsObject );

		$this->assertEquals(
			$statementArray,
			array_values( $list->toArray() )
		);
	}

	public function testGivenTraversableWithNonStatements_constructorThrowsException() {
		$claim = new Claim( new PropertyValueSnak( 42, new StringValue( 'foo' ) ) );
		$claim->setGuid( 'meh' );

		$claimArray = array(
			$this->getStatementWithSnak( 1, 'foo' ),
			$claim,
			$this->getStatementWithSnak( 2, 'bar' ),
		);

		$claimsObject = new Claims( $claimArray );

		$this->setExpectedException( 'InvalidArgumentException' );
		new StatementList( $claimsObject );
	}

	public function testGivenNonTraversable_constructorThrowsException() {
		$this->setExpectedException( 'InvalidArgumentException' );
		new StatementList( null );
	}

}
