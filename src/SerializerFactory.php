<?php

namespace Wikibase\DataModel;

use function class_alias;

class_alias(
	\Wikibase\DataModel\Serializers\SerializerFactory::class,
	  __NAMESPACE__ .'\SerializerFactory'
);
