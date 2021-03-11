<?php

use MediaWiki\MediaWikiServices;

return [
	'SDDatabase' => function ( MediaWikiServices $services ): SDDatabase {
		return new SDDatabase( $services->getDBLoadBalancerFactory()->getMainLB() );
	},
];
