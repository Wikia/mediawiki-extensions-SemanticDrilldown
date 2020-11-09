<?php

use MediaWiki\MediaWikiServices;

return [
	SDDatabase::class => function ( MediaWikiServices $services ): SDDatabase {
		$config = $services->getMainConfig();

		return new SDDatabase(
			$services->getDBLoadBalancerFactory(),
			$config->get('SMWDB'),
			$config->get('SMWDbName'),
		);
	},
];
