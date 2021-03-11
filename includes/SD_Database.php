<?php

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Defines a class, SDDatabase, that holds connection to DB.
 *
 * @author Tomasz Tomalak
 */
class SDDatabase {
	/** @var IDatabase */
	private $connection;

	public function __construct( ILoadBalancer $lb, $dbName = false ) {
		$this->connection = $lb->getConnectionRef( DB_REPLICA, [], $dbName );
	}

	public function getConnection(): IDatabase {
		return $this->connection;
	}
}
