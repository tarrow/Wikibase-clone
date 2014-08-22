<?php

namespace Wikibase\DataModel\Statement;

use InvalidArgumentException;
use Traversable;
use Wikibase\DataModel\Claim\Claims;
use Wikibase\DataModel\Claim\Statement;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Reference;
use Wikibase\DataModel\ReferenceList;
use Wikibase\DataModel\References;
use Wikibase\DataModel\Snak\Snak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Snak\Snaks;

/**
 * Ordered, non-unique, collection of Statement objects.
 * Provides various filter operations though does not do any indexing by default.
 *
 * @since 1.0
 *
 * @licence GNU GPL v2+
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 */
class StatementList implements \IteratorAggregate {

	/**
	 * @var Statement[]
	 */
	private $statements = array();

	/**
	 * @param Statement[]|Traversable $statements
	 *
	 * @throws InvalidArgumentException
	 */
	public function __construct( $statements = array() ) {
		$this->addStatements( $statements );
	}

	/**
	 * Returns the best statements per property.
	 * The best statements are those with the highest rank for a particular property.
	 * Deprecated ranks are never included.
	 *
	 * @return self
	 */
	public function getBestStatementPerProperty() {
		$statementList = new self();

		foreach ( $this->getPropertyIds() as $propertyId ) {
			$claims = new Claims( $this->statements );
			$statementList->addStatements( $claims->getClaimsForProperty( $propertyId )->getBestClaims() );
		}

		return $statementList;
	}

	private function addStatements( $statements ) {
		$this->assertAreStatements( $statements );

		foreach ( $statements as $statement ) {
			$this->statements[] = $statement;
		}
	}

	private function assertAreStatements( $statements ) {
		if ( !is_array( $statements ) && !( $statements instanceof Traversable ) ) {
			throw new InvalidArgumentException( '$statements should be an array or a Traversable' );
		}

		foreach ( $statements as $statement ) {
			if ( !( $statement instanceof Statement ) ) {
				throw new InvalidArgumentException( 'All elements need to be of type Statement' );
			}
		}
	}

	/**
	 * Returns the property ids used by the statements.
	 * The keys of the returned array hold the serializations of the property ids.
	 *
	 * @return PropertyId[]
	 */
	public function getPropertyIds() {
		$propertyIds = array();

		foreach ( $this->statements as $statement ) {
			$propertyIds[$statement->getPropertyId()->getSerialization()] = $statement->getPropertyId();
		}

		return $propertyIds;
	}

	public function addStatement( Statement $statement ) {
		$this->statements[] = $statement;
	}

	/**
	 * @param Snak $mainSnak
	 * @param Snak[]|Snaks|null $qualifiers
	 * @param Reference[]|References|null $references
	 * @param string|null $guid
	 */
	public function addNewStatement( Snak $mainSnak, $qualifiers = null, $references = null, $guid = null ) {
		$qualifiers = is_array( $qualifiers ) ? new SnakList( $qualifiers ) : $qualifiers;
		$references = is_array( $references ) ? new ReferenceList( $references ) : $references;

		$statement = new Statement( $mainSnak, $qualifiers, $references );
		$statement->setGuid( $guid );

		$this->addStatement( $statement );
	}

	/**
	 * Statements that have a main snak already in the list are filtered out.
	 * The last occurrences are retained.
	 *
	 * @return self
	 */
	public function getWithUniqueMainSnaks() {
		$statements = array();

		foreach ( $this->statements as $statement ) {
			$statements[$statement->getMainSnak()->getHash()] = $statement;
		}

		return new self( $statements );
	}

	/**
	 * @return Traversable
	 */
	public function getIterator() {
		return new \ArrayIterator( $this->statements );
	}

	/**
	 * @return Statement[]
	 */
	public function toArray() {
		return $this->statements;
	}

}
